<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Services\Zoom;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use VL\LMS\Services\Zoom\Exception\ZoomAuthException;
use VL\LMS\Services\Zoom\Settings\ZoomCredentials;
use VL\LMS\Services\Zoom\Settings\ZoomSettingsProvider;
use VL\LMS\Services\Zoom\TokenHttpClient;
use VL\LMS\Services\Zoom\TokenProvider;

final class TokenProviderTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private static function utc( string $value ): \DateTimeImmutable {
		return new \DateTimeImmutable( $value, new \DateTimeZone( 'UTC' ) );
	}

	private function settings_with_credentials(): ZoomSettingsProvider {
		return new class() extends ZoomSettingsProvider {
			public function get_credentials(): ZoomCredentials {
				return new ZoomCredentials( 'acc', 'cid', 'csec', 'whk' );
			}
		};
	}

	private function settings_without_credentials(): ZoomSettingsProvider {
		return new class() extends ZoomSettingsProvider {
			public function get_credentials(): ZoomCredentials {
				return new ZoomCredentials( '', '', '', '' );
			}
		};
	}

	/**
	 * @param array<string, mixed>|\Throwable $response
	 */
	private function http( $response ): TokenHttpClient {
		return new class($response) implements TokenHttpClient {
			/** @var array<string, mixed>|\Throwable */
			private $response;
			public int $calls = 0;
			public function __construct( $response ) {
				$this->response = $response;
			}
			public function request_token( ZoomCredentials $creds ): array {
				++$this->calls;
				if ( $this->response instanceof \Throwable ) {
					throw $this->response;
				}
				return $this->response;
			}
		};
	}

	public function test_get_token_returns_cached_value_when_not_expired(): void {
		Functions\when( 'get_transient' )->justReturn(
			[
				'token'          => 'cached-token',
				'expires_at_iso' => '2026-04-23T11:00:00Z',
			]
		);
		Functions\expect( 'set_transient' )->never();

		$http     = $this->http( [] );
		$provider = new TokenProvider(
			$this->settings_with_credentials(),
			$http,
			static fn (): \DateTimeImmutable => self::utc( '2026-04-23 10:00:00' )
		);

		self::assertSame( 'cached-token', $provider->get_token() );
		self::assertSame( 0, $http->calls );
	}

	public function test_get_token_fetches_when_transient_missing_and_writes_back(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		$captured_ttl = null;
		Functions\when( 'set_transient' )->alias(
			static function ( string $key, $value, int $ttl ) use ( &$captured_ttl ): bool {
				$captured_ttl = $ttl;
				return true;
			}
		);

		$http = $this->http(
			[
				'access_token' => 'fresh-token',
				'expires_in'   => 3600,
			]
		);

		$provider = new TokenProvider(
			$this->settings_with_credentials(),
			$http,
			static fn (): \DateTimeImmutable => self::utc( '2026-04-23 10:00:00' )
		);

		self::assertSame( 'fresh-token', $provider->get_token() );
		self::assertSame( 1, $http->calls );
		self::assertSame( 3540, $captured_ttl ); // 3600 - 60s skew.
	}

	public function test_get_token_refreshes_when_cached_token_within_skew(): void {
		Functions\when( 'get_transient' )->justReturn(
			[
				'token'          => 'stale-token',
				'expires_at_iso' => '2026-04-23T10:00:30Z',
			]
		);
		Functions\when( 'set_transient' )->justReturn( true );

		$http = $this->http(
			[
				'access_token' => 'fresh-token',
				'expires_in'   => 3600,
			]
		);

		$provider = new TokenProvider(
			$this->settings_with_credentials(),
			$http,
			static fn (): \DateTimeImmutable => self::utc( '2026-04-23 10:00:00' )
		);

		self::assertSame( 'fresh-token', $provider->get_token() );
		self::assertSame( 1, $http->calls );
	}

	public function test_invalidate_calls_delete_transient(): void {
		$called = false;
		Functions\when( 'delete_transient' )->alias(
			static function ( string $key ) use ( &$called ): bool {
				$called = ( 'vl_lms_zoom_access_token' === $key );
				return true;
			}
		);

		$provider = new TokenProvider(
			$this->settings_with_credentials(),
			$this->http( [] )
		);

		$provider->invalidate();

		self::assertTrue( $called );
	}

	public function test_get_token_throws_when_credentials_missing(): void {
		Functions\when( 'get_transient' )->justReturn( false );

		$provider = new TokenProvider(
			$this->settings_without_credentials(),
			$this->http( [] )
		);

		$this->expectException( ZoomAuthException::class );

		$provider->get_token();
	}

	public function test_get_token_throws_when_http_client_throws(): void {
		Functions\when( 'get_transient' )->justReturn( false );

		$provider = new TokenProvider(
			$this->settings_with_credentials(),
			$this->http( new ZoomAuthException( 'boom' ) )
		);

		$this->expectException( ZoomAuthException::class );

		$provider->get_token();
	}

	public function test_get_token_throws_when_response_missing_access_token(): void {
		Functions\when( 'get_transient' )->justReturn( false );

		$provider = new TokenProvider(
			$this->settings_with_credentials(),
			$this->http( [ 'expires_in' => 3600 ] )
		);

		$this->expectException( ZoomAuthException::class );

		$provider->get_token();
	}
}
