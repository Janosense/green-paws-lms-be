<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\User;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\User\InstructorProfileMetaRegistrar;

final class InstructorProfileMetaRegistrarTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array<string, array<string, mixed>> */
	private array $captured = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->captured = [];
		Functions\when( 'register_meta' )->alias(
			function (
				string $object_type,
				string $meta_key,
				array $args
			): bool {
				$this->captured[ $meta_key ] = [ 'object_type' => $object_type ] + $args;
				return true;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_register_registers_both_meta_keys_for_user_object_type(): void {
		( new InstructorProfileMetaRegistrar() )->register();

		self::assertArrayHasKey( 'vl_instructor_avatar_id', $this->captured );
		self::assertArrayHasKey( 'vl_instructor_bio', $this->captured );
		self::assertSame( 'user', $this->captured['vl_instructor_avatar_id']['object_type'] );
		self::assertSame( 'user', $this->captured['vl_instructor_bio']['object_type'] );
	}

	public function test_avatar_meta_uses_absint_sanitizer_and_integer_type(): void {
		( new InstructorProfileMetaRegistrar() )->register();

		$args = $this->captured['vl_instructor_avatar_id'];
		self::assertSame( 'integer', $args['type'] );
		self::assertTrue( $args['single'] );
		self::assertSame( 0, $args['default'] );
		self::assertFalse( $args['show_in_rest'] );
		self::assertSame( 'absint', $args['sanitize_callback'] );
		self::assertIsCallable( $args['auth_callback'] );
	}

	public function test_bio_meta_uses_wp_kses_post_sanitizer_and_string_type(): void {
		( new InstructorProfileMetaRegistrar() )->register();

		$args = $this->captured['vl_instructor_bio'];
		self::assertSame( 'string', $args['type'] );
		self::assertTrue( $args['single'] );
		self::assertSame( '', $args['default'] );
		self::assertFalse( $args['show_in_rest'] );
		self::assertSame( 'wp_kses_post', $args['sanitize_callback'] );
		self::assertIsCallable( $args['auth_callback'] );
	}

	public function test_avatar_auth_callback_defers_to_current_user_can_edit_user_true(): void {
		( new InstructorProfileMetaRegistrar() )->register();

		Functions\when( 'current_user_can' )->justReturn( true );
		$cb = $this->captured['vl_instructor_avatar_id']['auth_callback'];
		self::assertIsCallable( $cb );
		self::assertTrue( $cb( false, 'vl_instructor_avatar_id', 42 ) );
	}

	public function test_avatar_auth_callback_defers_to_current_user_can_edit_user_false(): void {
		( new InstructorProfileMetaRegistrar() )->register();

		Functions\when( 'current_user_can' )->justReturn( false );
		$cb = $this->captured['vl_instructor_avatar_id']['auth_callback'];
		self::assertIsCallable( $cb );
		self::assertFalse( $cb( true, 'vl_instructor_avatar_id', 42 ) );
	}

	public function test_bio_auth_callback_defers_to_current_user_can_edit_user(): void {
		( new InstructorProfileMetaRegistrar() )->register();

		Functions\when( 'current_user_can' )->justReturn( true );
		$cb = $this->captured['vl_instructor_bio']['auth_callback'];
		self::assertIsCallable( $cb );
		self::assertTrue( $cb( false, 'vl_instructor_bio', 42 ) );

		Functions\when( 'current_user_can' )->justReturn( false );
		self::assertFalse( $cb( true, 'vl_instructor_bio', 42 ) );
	}
}
