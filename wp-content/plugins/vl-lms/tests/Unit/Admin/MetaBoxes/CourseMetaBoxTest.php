<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Admin\MetaBoxes;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use VL\LMS\Admin\MetaBoxes\CourseMetaBox;
use WP_Post;

final class CourseMetaBoxTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const string NONCE_FIELD = 'vl_lms_vl_lms_course_nonce';

	/** @var list<array{0: int, 1: string, 2: mixed}> */
	private array $meta_writes = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'absint' )->alias( static fn ( $v ): int => (int) $v );
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_post_type' )->justReturn( 'vl_course' );

		$this->meta_writes = [];
		$writes            = &$this->meta_writes;
		Functions\when( 'update_post_meta' )->alias(
			static function ( int $id, string $key, mixed $value ) use ( &$writes ): bool {
				$writes[] = [ $id, $key, $value ];
				return true;
			}
		);

		$_POST = [];
	}

	protected function tearDown(): void {
		$_POST = [];
		Monkey\tearDown();
		parent::tearDown();
	}

	private static function postMock(): WP_Post {
		$post = Mockery::mock( 'WP_Post' );
		assert( $post instanceof WP_Post );
		return $post;
	}

	/**
	 * @return list<mixed>
	 */
	private function written_values_for( string $key ): array {
		$out = [];
		foreach ( $this->meta_writes as $row ) {
			if ( $row[1] === $key ) {
				$out[] = $row[2];
			}
		}
		return $out;
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_save_bails_on_autosave(): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WP core constant required by AbstractMetaBox::verified().
		define( 'DOING_AUTOSAVE', true );

		$_POST = [
			self::NONCE_FIELD  => 'nonce-x',
			'_vl_course_type'  => 'self_paced',
			'_vl_course_price' => '199.5',
		];

		( new CourseMetaBox() )->save( 42, self::postMock() );

		self::assertSame( [], $this->meta_writes, 'autosave must short-circuit save()' );
	}

	public function test_save_bails_on_failed_nonce(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		$_POST = [
			self::NONCE_FIELD => 'invalid',
			'_vl_course_type' => 'self_paced',
		];

		( new CourseMetaBox() )->save( 42, self::postMock() );

		self::assertSame( [], $this->meta_writes );
	}

	public function test_save_sanitizes_price_negative_to_zero(): void {
		$_POST = [
			self::NONCE_FIELD  => 'nonce-x',
			'_vl_course_price' => '-5.5',
		];

		( new CourseMetaBox() )->save( 42, self::postMock() );

		self::assertContains( 0.0, $this->written_values_for( '_vl_course_price' ), 'price clamps to 0.0' );
	}

	public function test_save_rejects_unknown_course_type(): void {
		$_POST = [
			self::NONCE_FIELD => 'nonce-x',
			'_vl_course_type' => 'unknown',
		];

		( new CourseMetaBox() )->save( 42, self::postMock() );

		self::assertSame( [], $this->written_values_for( '_vl_course_type' ), 'unknown enum value must be skipped' );
	}
}
