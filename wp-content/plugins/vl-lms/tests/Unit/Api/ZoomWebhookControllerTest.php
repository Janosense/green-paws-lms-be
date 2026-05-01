<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Api;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Api\ZoomWebhookController;
use VL\LMS\Domain\ZoomWebhook\WebhookProcessingStatus;
use VL\LMS\Services\Zoom\Settings\ZoomCredentials;
use VL\LMS\Services\Zoom\Webhook\DispatchResult;
use VL\LMS\Services\Zoom\Webhook\HandlerOutcome;
use VL\LMS\Services\Zoom\Webhook\UrlValidationResponder;
use VL\LMS\Services\Zoom\Webhook\WebhookEventDispatcher;
use VL\LMS\Services\Zoom\Webhook\WebhookRequestParser;
use VL\LMS\Services\Zoom\Webhook\WebhookSignatureVerifier;
use VL\LMS\Support\Logger;
use VL\LMS\Tests\Fixtures\Zoom\Sync\StubZoomSettingsProvider;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class ZoomWebhookControllerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const string SECRET = 'secret-XYZ';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'rest_ensure_response' )->alias(
			static function ( $payload ): WP_REST_Response {
				$response = Mockery::mock( WP_REST_Response::class )->makePartial();
				$response->shouldReceive( 'set_status' )->andReturnUsing(
					function ( int $status ) use ( $response ): WP_REST_Response {
						$response->status = $status;
						return $response;
					}
				);
				$response->shouldReceive( 'get_data' )->andReturn( $payload );
				$response->status = 200;
				return $response;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function settings( string $secret = self::SECRET ): StubZoomSettingsProvider {
		return new StubZoomSettingsProvider(
			new ZoomCredentials( 'a', 'b', 'c', $secret )
		);
	}

	private function build_controller(
		StubZoomSettingsProvider $settings,
		WebhookEventDispatcher $dispatcher,
		?\Closure $clock = null
	): ZoomWebhookController {
		$clock     = $clock ?? static fn (): int => 1714540800;
		$verifier  = new WebhookSignatureVerifier( $settings, $clock );
		$parser    = new WebhookRequestParser();
		$responder = new UrlValidationResponder();
		$logger    = Mockery::mock( Logger::class )->shouldIgnoreMissing();

		return new ZoomWebhookController(
			'vl/v1',
			$verifier,
			$parser,
			$responder,
			$dispatcher,
			$settings,
			$logger
		);
	}

	/**
	 * @param array<string, string> $headers
	 */
	private function request( string $body, array $headers = [] ): WP_REST_Request {
		$req = Mockery::mock( WP_REST_Request::class );
		$req->shouldReceive( 'get_body' )->andReturn( $body );
		$req->shouldReceive( 'get_header' )->andReturnUsing(
			static function ( string $name ) use ( $headers ): ?string {
				return $headers[ strtolower( $name ) ] ?? null;
			}
		);
		return $req;
	}

	private function sign( string $body, int $timestamp, string $secret = self::SECRET ): string {
		return 'v0=' . hash_hmac( 'sha256', 'v0:' . $timestamp . ':' . $body, $secret );
	}

	public function test_returns_401_misconfigured_when_secret_missing(): void {
		$dispatcher = Mockery::mock( WebhookEventDispatcher::class );
		$dispatcher->shouldNotReceive( 'dispatch' );

		$controller = $this->build_controller( $this->settings( '' ), $dispatcher );

		$result = $controller->handle(
			$this->request(
				'body',
				[
					'x-zm-signature'         => 'v0=abc',
					'x-zm-request-timestamp' => '1714540800',
				]
			)
		);

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'webhook_misconfigured', $result->get_error_code() );
		$data = $result->get_error_data();
		self::assertSame( 401, $data['status'] );
	}

	public function test_returns_401_invalid_signature_when_headers_missing(): void {
		$dispatcher = Mockery::mock( WebhookEventDispatcher::class );
		$dispatcher->shouldNotReceive( 'dispatch' );

		$controller = $this->build_controller( $this->settings(), $dispatcher );

		$result = $controller->handle( $this->request( 'body', [] ) );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'webhook_invalid_signature', $result->get_error_code() );
		$data = $result->get_error_data();
		self::assertSame( 401, $data['status'] );
	}

	public function test_returns_401_invalid_signature_for_replay_window(): void {
		$body = 'body';
		// timestamp 600s in the past
		$timestamp = 1714540200;
		$signature = $this->sign( $body, $timestamp );

		$dispatcher = Mockery::mock( WebhookEventDispatcher::class );
		$dispatcher->shouldNotReceive( 'dispatch' );

		$controller = $this->build_controller( $this->settings(), $dispatcher );

		$result = $controller->handle(
			$this->request(
				$body,
				[
					'x-zm-signature'         => $signature,
					'x-zm-request-timestamp' => (string) $timestamp,
				]
			)
		);

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'webhook_invalid_signature', $result->get_error_code() );
	}

	public function test_returns_401_invalid_signature_on_mismatch(): void {
		$dispatcher = Mockery::mock( WebhookEventDispatcher::class );
		$dispatcher->shouldNotReceive( 'dispatch' );

		$controller = $this->build_controller( $this->settings(), $dispatcher );

		$result = $controller->handle(
			$this->request(
				'body',
				[
					'x-zm-signature'         => 'v0=wrong',
					'x-zm-request-timestamp' => '1714540800',
				]
			)
		);

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'webhook_invalid_signature', $result->get_error_code() );
	}

	public function test_returns_400_invalid_payload_for_bad_json(): void {
		$body      = 'not json';
		$timestamp = 1714540800;
		$signature = $this->sign( $body, $timestamp );

		$dispatcher = Mockery::mock( WebhookEventDispatcher::class );
		$dispatcher->shouldNotReceive( 'dispatch' );

		$controller = $this->build_controller( $this->settings(), $dispatcher );

		$result = $controller->handle(
			$this->request(
				$body,
				[
					'x-zm-signature'         => $signature,
					'x-zm-request-timestamp' => (string) $timestamp,
				]
			)
		);

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'webhook_invalid_payload', $result->get_error_code() );
		$data = $result->get_error_data();
		self::assertSame( 400, $data['status'] );
	}

	public function test_url_validation_returns_200_with_encrypted_token(): void {
		$body      = json_encode(
			[
				'event'   => 'endpoint.url_validation',
				'payload' => [ 'plainToken' => 'TOKEN_123' ],
			],
			JSON_THROW_ON_ERROR
		);
		$timestamp = 1714540800;
		$signature = $this->sign( $body, $timestamp );

		$dispatcher = Mockery::mock( WebhookEventDispatcher::class );
		$dispatcher->shouldNotReceive( 'dispatch' );

		$controller = $this->build_controller( $this->settings(), $dispatcher );

		$result = $controller->handle(
			$this->request(
				$body,
				[
					'x-zm-signature'         => $signature,
					'x-zm-request-timestamp' => (string) $timestamp,
				]
			)
		);

		self::assertInstanceOf( WP_REST_Response::class, $result );
		$data = $result->get_data();
		self::assertSame( 'TOKEN_123', $data['plainToken'] );
		self::assertSame( hash_hmac( 'sha256', 'TOKEN_123', self::SECRET ), $data['encryptedToken'] );
	}

	public function test_operational_event_returns_200_with_processed_status(): void {
		$body      = json_encode(
			[
				'event'    => 'meeting.started',
				'event_ts' => 1714540800000,
				'payload'  => [
					'account_id' => 'A',
					'object'     => [ 'id' => '1' ],
				],
			],
			JSON_THROW_ON_ERROR
		);
		$timestamp = 1714540800;
		$signature = $this->sign( $body, $timestamp );

		$dispatcher = Mockery::mock( WebhookEventDispatcher::class );
		$dispatcher->shouldReceive( 'dispatch' )->once()->andReturn(
			DispatchResult::processed( HandlerOutcome::applied( 'status_advanced_to_live', 'ok' ) )
		);

		$controller = $this->build_controller( $this->settings(), $dispatcher );

		$result = $controller->handle(
			$this->request(
				$body,
				[
					'x-zm-signature'         => $signature,
					'x-zm-request-timestamp' => (string) $timestamp,
					'x-zm-trackingid'        => 'tr-1',
				]
			)
		);

		self::assertInstanceOf( WP_REST_Response::class, $result );
		$data = $result->get_data();
		self::assertTrue( $data['success'] );
		self::assertSame( 'processed', $data['status'] );
		self::assertSame( 'status_advanced_to_live', $data['message'] );
	}

	public function test_dispatcher_ignored_still_returns_200(): void {
		$body      = json_encode(
			[
				'event'    => 'meeting.started',
				'event_ts' => 1714540800000,
				'payload'  => [
					'object' => [ 'id' => '1' ],
				],
			],
			JSON_THROW_ON_ERROR
		);
		$timestamp = 1714540800;
		$signature = $this->sign( $body, $timestamp );

		$dispatcher = Mockery::mock( WebhookEventDispatcher::class );
		$dispatcher->shouldReceive( 'dispatch' )->once()->andReturn(
			DispatchResult::ignored( 'duplicate_tracking_id' )
		);

		$controller = $this->build_controller( $this->settings(), $dispatcher );

		$result = $controller->handle(
			$this->request(
				$body,
				[
					'x-zm-signature'         => $signature,
					'x-zm-request-timestamp' => (string) $timestamp,
					'x-zm-trackingid'        => 'tr-1',
				]
			)
		);

		self::assertInstanceOf( WP_REST_Response::class, $result );
		$data = $result->get_data();
		self::assertSame( 'ignored', $data['status'] );
		self::assertSame( 'duplicate_tracking_id', $data['message'] );
	}

	public function test_dispatcher_failed_still_returns_200(): void {
		$body      = json_encode(
			[
				'event'    => 'meeting.started',
				'event_ts' => 1714540800000,
				'payload'  => [
					'object' => [ 'id' => '1' ],
				],
			],
			JSON_THROW_ON_ERROR
		);
		$timestamp = 1714540800;
		$signature = $this->sign( $body, $timestamp );

		$dispatcher = Mockery::mock( WebhookEventDispatcher::class );
		$dispatcher->shouldReceive( 'dispatch' )->once()->andReturn(
			DispatchResult::failed( new \RuntimeException( 'kaboom' ) )
		);

		$controller = $this->build_controller( $this->settings(), $dispatcher );

		$result = $controller->handle(
			$this->request(
				$body,
				[
					'x-zm-signature'         => $signature,
					'x-zm-request-timestamp' => (string) $timestamp,
					'x-zm-trackingid'        => 'tr-1',
				]
			)
		);

		self::assertInstanceOf( WP_REST_Response::class, $result );
		$data = $result->get_data();
		self::assertSame( 'failed', $data['status'] );
	}
}
