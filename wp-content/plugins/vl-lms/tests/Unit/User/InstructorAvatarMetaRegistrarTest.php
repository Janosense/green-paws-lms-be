<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\User;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\User\InstructorAvatarMetaRegistrar;

final class InstructorAvatarMetaRegistrarTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_register_calls_register_meta_for_user_with_instructor_avatar_key(): void {
		$captured_object_type = null;
		$captured_meta_key    = null;
		$captured_args        = null;

		Functions\expect( 'register_meta' )
			->once()
			->andReturnUsing(
				static function (
					string $object_type,
					string $meta_key,
					array $args
				) use (
					&$captured_object_type,
					&$captured_meta_key,
					&$captured_args
				): bool {
					$captured_object_type = $object_type;
					$captured_meta_key    = $meta_key;
					$captured_args        = $args;
					return true;
				}
			);

		( new InstructorAvatarMetaRegistrar() )->register();

		self::assertSame( 'user', $captured_object_type );
		self::assertSame( 'vl_instructor_avatar_id', $captured_meta_key );
		self::assertIsArray( $captured_args );
	}

	public function test_register_passes_expected_meta_arguments(): void {
		$captured_args = null;

		Functions\when( 'register_meta' )->alias(
			static function ( string $object_type, string $meta_key, array $args ) use ( &$captured_args ): bool {
				$captured_args = $args;
				return true;
			}
		);

		( new InstructorAvatarMetaRegistrar() )->register();

		self::assertIsArray( $captured_args );
		self::assertSame( 'integer', $captured_args['type'] );
		self::assertTrue( $captured_args['single'] );
		self::assertSame( 0, $captured_args['default'] );
		self::assertFalse( $captured_args['show_in_rest'] );
		self::assertSame( 'absint', $captured_args['sanitize_callback'] );
		self::assertIsCallable( $captured_args['auth_callback'] );
	}

	public function test_auth_callback_returns_true_when_current_user_can_edit_user_returns_true(): void {
		$captured_args = null;

		Functions\when( 'register_meta' )->alias(
			static function ( string $object_type, string $meta_key, array $args ) use ( &$captured_args ): bool {
				$captured_args = $args;
				return true;
			}
		);

		( new InstructorAvatarMetaRegistrar() )->register();
		self::assertIsArray( $captured_args );

		Functions\when( 'current_user_can' )->justReturn( true );

		$callback = $captured_args['auth_callback'];
		self::assertIsCallable( $callback );
		self::assertTrue( $callback( false, 'vl_instructor_avatar_id', 42 ) );
	}

	public function test_auth_callback_returns_false_when_current_user_can_edit_user_returns_false(): void {
		$captured_args = null;

		Functions\when( 'register_meta' )->alias(
			static function ( string $object_type, string $meta_key, array $args ) use ( &$captured_args ): bool {
				$captured_args = $args;
				return true;
			}
		);

		( new InstructorAvatarMetaRegistrar() )->register();
		self::assertIsArray( $captured_args );

		Functions\when( 'current_user_can' )->justReturn( false );

		$callback = $captured_args['auth_callback'];
		self::assertIsCallable( $callback );
		self::assertFalse( $callback( true, 'vl_instructor_avatar_id', 42 ) );
	}
}
