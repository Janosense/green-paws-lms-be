<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Payments\LiqPay;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use VL\LMS\Payments\Exception\PaymentProviderHttpException;
use VL\LMS\Payments\LiqPay\HttpClient;
use WP_Error;

final class HttpClientTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'is_wp_error' )->alias(
			static fn ( $value ): bool => $value instanceof WP_Error
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static fn ( array $response ): int => (int) ( $response['response']['code'] ?? 0 )
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			static fn ( array $response ): string => (string) ( $response['body'] ?? '' )
		);
		Functions\when( 'wp_remote_retrieve_headers' )->alias(
			static fn ( array $response ): mixed => $response['headers'] ?? []
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_post_returns_envelope_for_2xx_response(): void {
		$client = new class() extends HttpClient {

			public ?string $captured_url = null;
			/** @var array<string, mixed>|null */
			public ?array $captured_args = null;

			protected function transport( string $url, array $args ): mixed {
				$this->captured_url  = $url;
				$this->captured_args = $args;
				return [
					'response' => [ 'code' => 200 ],
					'body'     => '{"status":"reversed","payment_id":42}',
					'headers'  => [ 'X-Liqpay' => 'ok' ],
				];
			}
		};

		$result = $client->post( 'data-b64', 'sig-b64' );

		self::assertSame( HttpClient::REQUEST_URL, $client->captured_url );
		self::assertSame( 'data-b64', $client->captured_args['body']['data'] );
		self::assertSame( 'sig-b64', $client->captured_args['body']['signature'] );
		self::assertSame( HttpClient::TIMEOUT_SECONDS, $client->captured_args['timeout'] );
		self::assertSame( 0, $client->captured_args['redirection'] );

		self::assertSame( 200, $result['status_code'] );
		self::assertSame( '{"status":"reversed","payment_id":42}', $result['body'] );
		self::assertSame( 'ok', $result['headers']['X-Liqpay'] );
	}

	public function test_post_throws_when_transport_returns_wp_error(): void {
		$client = new class() extends HttpClient {

			protected function transport( string $url, array $args ): mixed {
				$err = \Mockery::mock( WP_Error::class );
				$err->shouldReceive( 'get_error_message' )->andReturn( 'cURL timeout' );
				return $err;
			}
		};

		$this->expectException( PaymentProviderHttpException::class );
		$this->expectExceptionMessageMatches( '/cURL timeout/' );
		$client->post( 'data', 'sig' );
	}

	public function test_post_throws_for_4xx_response(): void {
		$client = new class() extends HttpClient {

			protected function transport( string $url, array $args ): mixed {
				return [
					'response' => [ 'code' => 400 ],
					'body'     => 'bad request',
					'headers'  => [],
				];
			}
		};

		try {
			$client->post( 'data', 'sig' );
			self::fail( 'Expected PaymentProviderHttpException' );
		} catch ( PaymentProviderHttpException $ex ) {
			self::assertSame( 400, $ex->http_status() );
			self::assertSame( 'bad request', $ex->response_body() );
		}
	}

	public function test_post_throws_for_5xx_response(): void {
		$client = new class() extends HttpClient {

			protected function transport( string $url, array $args ): mixed {
				return [
					'response' => [ 'code' => 503 ],
					'body'     => 'service unavailable',
					'headers'  => [],
				];
			}
		};

		try {
			$client->post( 'data', 'sig' );
			self::fail( 'Expected PaymentProviderHttpException' );
		} catch ( PaymentProviderHttpException $ex ) {
			self::assertSame( 503, $ex->http_status() );
		}
	}
}
