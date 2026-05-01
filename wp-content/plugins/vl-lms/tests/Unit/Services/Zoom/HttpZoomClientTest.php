<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Services\Zoom;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use VL\LMS\Services\Zoom\Exception\ZoomApiException;
use VL\LMS\Services\Zoom\Exception\ZoomAuthException;
use VL\LMS\Services\Zoom\HttpZoomClient;
use VL\LMS\Tests\Fixtures\Zoom\FakeTokenProvider;
use VL\LMS\Tests\Fixtures\Zoom\FakeZoomApiHttpClient;

final class HttpZoomClientTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'wp_json_encode' )->alias(
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Brain Monkey shim aliases wp_json_encode in the unit-test runtime; using json_encode here is the shim's whole point.
			static fn ( $value ): string|false => json_encode( $value )
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_create_meeting_posts_to_users_meetings_with_bearer_token(): void {
		$http   = new FakeZoomApiHttpClient(
			[
				[
					'status' => 201,
					'body'   => '{"id":"123","topic":"Demo"}',
				],
			]
		);
		$client = new HttpZoomClient( new FakeTokenProvider( [ 'tok-1' ] ), $http );

		$result = $client->create_meeting( 'me', [ 'topic' => 'Demo' ] );

		self::assertSame(
			[
				'id'    => '123',
				'topic' => 'Demo',
			],
			$result
		);
		self::assertCount( 1, $http->calls );
		self::assertSame( 'POST', $http->calls[0]['method'] );
		self::assertSame( 'https://api.zoom.us/v2/users/me/meetings', $http->calls[0]['url'] );
		self::assertSame( 'Bearer tok-1', $http->calls[0]['headers']['Authorization'] );
		self::assertSame( 'application/json', $http->calls[0]['headers']['Content-Type'] );
		self::assertSame( '{"topic":"Demo"}', $http->calls[0]['body'] );
	}

	public function test_update_meeting_uses_patch_method(): void {
		$http   = new FakeZoomApiHttpClient(
			[
				[
					'status' => 204,
					'body'   => '',
				],
			]
		);
		$client = new HttpZoomClient( new FakeTokenProvider( [ 'tok-1' ] ), $http );

		$client->update_meeting( 'mtg-1', [ 'topic' => 'New' ] );

		self::assertSame( 'PATCH', $http->calls[0]['method'] );
		self::assertSame( 'https://api.zoom.us/v2/meetings/mtg-1', $http->calls[0]['url'] );
	}

	public function test_delete_meeting_uses_delete_method(): void {
		$http   = new FakeZoomApiHttpClient(
			[
				[
					'status' => 204,
					'body'   => '',
				],
			]
		);
		$client = new HttpZoomClient( new FakeTokenProvider( [ 'tok-1' ] ), $http );

		$client->delete_meeting( 'mtg-1' );

		self::assertSame( 'DELETE', $http->calls[0]['method'] );
		self::assertSame( 'https://api.zoom.us/v2/meetings/mtg-1', $http->calls[0]['url'] );
	}

	public function test_delete_meeting_appends_cancel_reminder_query_when_requested(): void {
		$http   = new FakeZoomApiHttpClient(
			[
				[
					'status' => 204,
					'body'   => '',
				],
			]
		);
		$client = new HttpZoomClient( new FakeTokenProvider( [ 'tok-1' ] ), $http );

		$client->delete_meeting( 'mtg-1', true );

		self::assertStringContainsString( 'cancel_meeting_reminder=true', $http->calls[0]['url'] );
	}

	public function test_get_meeting_returns_decoded_body(): void {
		$http   = new FakeZoomApiHttpClient(
			[
				[
					'status' => 200,
					'body'   => '{"id":"mtg-1","topic":"Demo"}',
				],
			]
		);
		$client = new HttpZoomClient( new FakeTokenProvider( [ 'tok-1' ] ), $http );

		$result = $client->get_meeting( 'mtg-1' );

		self::assertSame(
			[
				'id'    => 'mtg-1',
				'topic' => 'Demo',
			],
			$result
		);
		self::assertSame( 'GET', $http->calls[0]['method'] );
	}

	public function test_list_recordings_hits_recordings_subpath(): void {
		$http   = new FakeZoomApiHttpClient(
			[
				[
					'status' => 200,
					'body'   => '{"recording_files":[]}',
				],
			]
		);
		$client = new HttpZoomClient( new FakeTokenProvider( [ 'tok-1' ] ), $http );

		$result = $client->list_recordings( 'mtg-1' );

		self::assertSame( [ 'recording_files' => [] ], $result );
		self::assertSame( 'https://api.zoom.us/v2/meetings/mtg-1/recordings', $http->calls[0]['url'] );
	}

	public function test_401_then_success_invalidates_and_retries(): void {
		$http   = new FakeZoomApiHttpClient(
			[
				[
					'status' => 401,
					'body'   => '{"code":124,"message":"Invalid access token."}',
				],
				[
					'status' => 200,
					'body'   => '{"id":"mtg-1"}',
				],
			]
		);
		$tokens = new FakeTokenProvider( [ 'stale', 'fresh' ] );
		$client = new HttpZoomClient( $tokens, $http );

		$result = $client->get_meeting( 'mtg-1' );

		self::assertSame( [ 'id' => 'mtg-1' ], $result );
		self::assertCount( 2, $http->calls );
		self::assertSame( 1, $tokens->invalidate_calls );
		self::assertSame( 'Bearer stale', $http->calls[0]['headers']['Authorization'] );
		self::assertSame( 'Bearer fresh', $http->calls[1]['headers']['Authorization'] );
	}

	public function test_401_twice_throws_zoom_auth_exception(): void {
		$http   = new FakeZoomApiHttpClient(
			[
				[
					'status' => 401,
					'body'   => '{}',
				],
				[
					'status' => 401,
					'body'   => '{}',
				],
			]
		);
		$client = new HttpZoomClient( new FakeTokenProvider( [ 'tok-1', 'tok-2' ] ), $http );

		$this->expectException( ZoomAuthException::class );

		$client->get_meeting( 'mtg-1' );
	}

	public function test_4xx_parses_zoom_code_and_message(): void {
		$http   = new FakeZoomApiHttpClient(
			[
				[
					'status' => 404,
					'body'   => '{"code":3001,"message":"Meeting does not exist."}',
				],
			]
		);
		$client = new HttpZoomClient( new FakeTokenProvider( [ 'tok-1' ] ), $http );

		try {
			$client->get_meeting( 'mtg-1' );
			self::fail( 'Expected ZoomApiException.' );
		} catch ( ZoomApiException $e ) {
			self::assertSame( 404, $e->http_status );
			self::assertSame( '3001', $e->zoom_code );
			self::assertSame( 'Meeting does not exist.', $e->zoom_message );
		}
	}

	public function test_network_failure_propagates_zoom_api_exception(): void {
		$http   = new FakeZoomApiHttpClient(
			[
				new ZoomApiException( 'Could not resolve host', 0 ),
			]
		);
		$client = new HttpZoomClient( new FakeTokenProvider( [ 'tok-1' ] ), $http );

		$this->expectException( ZoomApiException::class );

		$client->get_meeting( 'mtg-1' );
	}

	public function test_create_meeting_against_specific_user_id(): void {
		$http   = new FakeZoomApiHttpClient(
			[
				[
					'status' => 201,
					'body'   => '{"id":"42"}',
				],
			]
		);
		$client = new HttpZoomClient( new FakeTokenProvider( [ 'tok-1' ] ), $http );

		$client->create_meeting( 'instructor@example.com', [ 'topic' => 'X' ] );

		self::assertSame(
			'https://api.zoom.us/v2/users/instructor%40example.com/meetings',
			$http->calls[0]['url']
		);
	}
}
