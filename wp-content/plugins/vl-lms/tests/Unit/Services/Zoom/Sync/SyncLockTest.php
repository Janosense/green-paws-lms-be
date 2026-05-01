<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Services\Zoom\Sync;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use VL\LMS\Services\Zoom\Sync\SyncLock;
use VL\LMS\Tests\Fixtures\Zoom\Sync\InMemorySyncLock;

final class SyncLockTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_try_acquire_returns_true_when_unheld_and_writes_lock(): void {
		$lock = new InMemorySyncLock();

		self::assertTrue( $lock->try_acquire( 42 ) );
		self::assertArrayHasKey( 42, $lock->held );
	}

	public function test_double_acquire_returns_false(): void {
		$lock = new InMemorySyncLock();
		$lock->try_acquire( 42 );

		self::assertFalse( $lock->try_acquire( 42 ) );
	}

	public function test_release_clears_lock(): void {
		$lock = new InMemorySyncLock();
		$lock->try_acquire( 42 );

		$lock->release( 42 );

		self::assertArrayNotHasKey( 42, $lock->held );
		self::assertSame( [ 42 ], $lock->release_calls );
		self::assertTrue( $lock->try_acquire( 42 ) );
	}

	public function test_real_lock_uses_transient_keyed_by_post_id(): void {
		$captured_key = null;
		$captured_ttl = null;
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->alias(
			static function ( string $key, $value, int $ttl ) use ( &$captured_key, &$captured_ttl ): bool {
				$captured_key = $key;
				$captured_ttl = $ttl;
				return true;
			}
		);

		$lock = new SyncLock();
		self::assertTrue( $lock->try_acquire( 99 ) );

		self::assertSame( 'vl_lms_zoom_sync_lock_99', $captured_key );
		self::assertSame( 30, $captured_ttl );
	}

	public function test_real_release_calls_delete_transient(): void {
		$deleted = null;
		Functions\when( 'delete_transient' )->alias(
			static function ( string $key ) use ( &$deleted ): bool {
				$deleted = $key;
				return true;
			}
		);

		$lock = new SyncLock();
		$lock->release( 99 );

		self::assertSame( 'vl_lms_zoom_sync_lock_99', $deleted );
	}
}
