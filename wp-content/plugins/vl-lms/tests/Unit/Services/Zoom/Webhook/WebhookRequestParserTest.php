<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Services\Zoom\Webhook;

use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Services\Zoom\Webhook\WebhookRequestException;
use VL\LMS\Services\Zoom\Webhook\WebhookRequestParser;
use WP_REST_Request;

final class WebhookRequestParserTest extends TestCase {

	use MockeryPHPUnitIntegration;

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

	public function test_happy_operational_event(): void {
		$body = json_encode(
			[
				'event'    => 'meeting.started',
				'event_ts' => 1714540800000,
				'payload'  => [
					'account_id' => 'AAA',
					'object'     => [
						'id' => '1234567890',
					],
				],
			],
			JSON_THROW_ON_ERROR
		);

		$parsed = ( new WebhookRequestParser() )->parse(
			$this->request( $body, [ 'x-zm-trackingid' => 'tr-123' ] )
		);

		self::assertSame( 'meeting.started', $parsed->event );
		self::assertSame( '1234567890', $parsed->object_id() );
		self::assertSame( 'AAA', $parsed->account_id );
		self::assertSame( 'tr-123', $parsed->tracking_id );
		self::assertSame( 1714540800000, $parsed->event_ts_ms );
		self::assertSame( $body, $parsed->raw_body );
		self::assertFalse( $parsed->is_url_validation() );
	}

	public function test_happy_url_validation(): void {
		$body = json_encode(
			[
				'event'   => 'endpoint.url_validation',
				'payload' => [
					'plainToken' => 'TOKEN_123',
				],
			],
			JSON_THROW_ON_ERROR
		);

		$parsed = ( new WebhookRequestParser() )->parse( $this->request( $body, [] ) );

		self::assertTrue( $parsed->is_url_validation() );
		self::assertSame( 'TOKEN_123', $parsed->url_validation_plain_token );
		self::assertSame( '', $parsed->tracking_id );
	}

	public function test_invalid_json(): void {
		try {
			( new WebhookRequestParser() )->parse( $this->request( '{not json', [] ) );
			self::fail( 'expected exception' );
		} catch ( WebhookRequestException $e ) {
			self::assertSame( 'invalid_json', $e->reason_code() );
		}
	}

	public function test_non_object_json_root(): void {
		try {
			( new WebhookRequestParser() )->parse( $this->request( '"hello"', [] ) );
			self::fail( 'expected exception' );
		} catch ( WebhookRequestException $e ) {
			self::assertSame( 'invalid_json', $e->reason_code() );
		}
	}

	public function test_missing_event(): void {
		try {
			( new WebhookRequestParser() )->parse( $this->request( '{"payload": {"object":{"id":"x"}}}', [] ) );
			self::fail( 'expected exception' );
		} catch ( WebhookRequestException $e ) {
			self::assertSame( 'missing_event', $e->reason_code() );
		}
	}

	public function test_invalid_payload_not_object(): void {
		try {
			( new WebhookRequestParser() )->parse(
				$this->request( '{"event":"meeting.started","payload":"x"}', [] )
			);
			self::fail( 'expected exception' );
		} catch ( WebhookRequestException $e ) {
			self::assertSame( 'invalid_payload', $e->reason_code() );
		}
	}

	public function test_missing_plain_token(): void {
		try {
			( new WebhookRequestParser() )->parse(
				$this->request( '{"event":"endpoint.url_validation","payload":{}}', [] )
			);
			self::fail( 'expected exception' );
		} catch ( WebhookRequestException $e ) {
			self::assertSame( 'missing_plain_token', $e->reason_code() );
		}
	}

	public function test_invalid_payload_object(): void {
		$body = '{"event":"meeting.started","event_ts":1,"payload":{"account_id":"A"}}';
		try {
			( new WebhookRequestParser() )->parse( $this->request( $body, [ 'x-zm-trackingid' => 'tr' ] ) );
			self::fail( 'expected exception' );
		} catch ( WebhookRequestException $e ) {
			self::assertSame( 'invalid_payload_object', $e->reason_code() );
		}
	}

	public function test_missing_tracking_id(): void {
		$body = '{"event":"meeting.started","event_ts":1,"payload":{"object":{"id":"x"}}}';
		try {
			( new WebhookRequestParser() )->parse( $this->request( $body, [] ) );
			self::fail( 'expected exception' );
		} catch ( WebhookRequestException $e ) {
			self::assertSame( 'missing_tracking_id', $e->reason_code() );
		}
	}

	public function test_missing_event_ts(): void {
		$body = '{"event":"meeting.started","payload":{"object":{"id":"x"}}}';
		try {
			( new WebhookRequestParser() )->parse( $this->request( $body, [ 'x-zm-trackingid' => 'tr' ] ) );
			self::fail( 'expected exception' );
		} catch ( WebhookRequestException $e ) {
			self::assertSame( 'missing_event_ts', $e->reason_code() );
		}
	}

	public function test_event_ts_string_digits_accepted(): void {
		$body   = '{"event":"meeting.started","event_ts":"1714540800000","payload":{"object":{"id":"x"}}}';
		$parsed = ( new WebhookRequestParser() )->parse( $this->request( $body, [ 'x-zm-trackingid' => 'tr' ] ) );

		self::assertSame( 1714540800000, $parsed->event_ts_ms );
	}
}
