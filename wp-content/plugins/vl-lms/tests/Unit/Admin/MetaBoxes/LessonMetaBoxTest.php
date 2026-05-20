<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Admin\MetaBoxes;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Admin\MetaBoxes\LessonMetaBox;
use WP_Post;

final class LessonMetaBoxTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const string NONCE_FIELD = 'vl_lms_vl_lms_lesson_nonce';

	/** @var list<array{0: int, 1: string, 2: mixed}> */
	private array $writes = [];

	/** @var list<array<string, mixed>> */
	private array $post_updates = [];

	/** @var array<int, int> */
	private array $post_parents = [];

	private int $lesson_parent = 0;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_post_type' )->alias(
			static function ( int|string $id ): string {
				return match ( (int) $id ) {
					77      => 'vl_course',
					78      => 'vl_course',
					99      => 'vl_module',
					100     => 'vl_module',
					88      => 'page',
					default => 'vl_lesson',
				};
			}
		);

		$this->post_parents  = [];
		$this->lesson_parent = 0;
		$lesson_parent       = &$this->lesson_parent;
		$post_parents        = &$this->post_parents;
		Functions\when( 'get_post_field' )->alias(
			static function ( string $field, int $id ) use ( &$lesson_parent, &$post_parents ): int {
				if ( 'post_parent' !== $field ) {
					return 0;
				}
				if ( array_key_exists( $id, $post_parents ) ) {
					return $post_parents[ $id ];
				}
				return $lesson_parent;
			}
		);

		$this->writes       = [];
		$this->post_updates = [];
		$writes             = &$this->writes;
		$post_updates       = &$this->post_updates;
		Functions\when( 'update_post_meta' )->alias(
			static function ( int $id, string $key, mixed $value ) use ( &$writes ): bool {
				$writes[] = [ $id, $key, $value ];
				return true;
			}
		);
		Functions\when( 'wp_update_post' )->alias(
			static function ( array $postarr ) use ( &$post_updates ): int {
				$post_updates[] = $postarr;
				return (int) ( $postarr['ID'] ?? 0 );
			}
		);

		$_POST = [];
	}

	protected function tearDown(): void {
		$_POST = [];
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_save_rejects_unknown_video_provider(): void {
		$_POST = [
			self::NONCE_FIELD           => 'nonce-x',
			'_vl_lesson_video_provider' => 'twitch',
		];

		$post = Mockery::mock( 'WP_Post' );
		assert( $post instanceof WP_Post );
		( new LessonMetaBox() )->save( 13, $post );

		$provider_writes = array_filter(
			$this->writes,
			static fn ( array $row ): bool => '_vl_lesson_video_provider' === $row[1]
		);
		self::assertSame( [], $provider_writes, 'Unknown provider must be skipped' );
	}

	public function test_save_writes_post_parent_to_course_when_only_course_selected(): void {
		$this->lesson_parent = 0;

		$_POST = [
			self::NONCE_FIELD      => 'nonce-x',
			'_vl_lesson_course_id' => '77',
			'_vl_lesson_module_id' => '0',
		];

		( new LessonMetaBox() )->save( 44, Mockery::mock( 'WP_Post' ) );

		self::assertCount( 1, $this->post_updates );
		self::assertSame( 44, $this->post_updates[0]['ID'] );
		self::assertSame( 77, $this->post_updates[0]['post_parent'] );
	}

	public function test_save_writes_post_parent_to_module_when_course_and_module_match(): void {
		$this->lesson_parent    = 0;
		$this->post_parents[99] = 77;

		$_POST = [
			self::NONCE_FIELD      => 'nonce-x',
			'_vl_lesson_course_id' => '77',
			'_vl_lesson_module_id' => '99',
		];

		( new LessonMetaBox() )->save( 44, Mockery::mock( 'WP_Post' ) );

		self::assertCount( 1, $this->post_updates );
		self::assertSame( 99, $this->post_updates[0]['post_parent'] );
	}

	public function test_save_falls_back_to_course_when_module_belongs_to_different_course(): void {
		$this->lesson_parent     = 0;
		$this->post_parents[100] = 999;

		$_POST = [
			self::NONCE_FIELD      => 'nonce-x',
			'_vl_lesson_course_id' => '77',
			'_vl_lesson_module_id' => '100',
		];

		( new LessonMetaBox() )->save( 44, Mockery::mock( 'WP_Post' ) );

		self::assertCount( 1, $this->post_updates );
		self::assertSame( 77, $this->post_updates[0]['post_parent'] );
	}

	public function test_save_falls_back_to_course_when_module_id_is_not_a_module(): void {
		$this->lesson_parent = 0;

		$_POST = [
			self::NONCE_FIELD      => 'nonce-x',
			'_vl_lesson_course_id' => '77',
			'_vl_lesson_module_id' => '88',
		];

		( new LessonMetaBox() )->save( 44, Mockery::mock( 'WP_Post' ) );

		self::assertCount( 1, $this->post_updates );
		self::assertSame( 77, $this->post_updates[0]['post_parent'] );
	}

	public function test_save_skips_update_when_parent_unchanged(): void {
		$this->lesson_parent = 77;

		$_POST = [
			self::NONCE_FIELD      => 'nonce-x',
			'_vl_lesson_course_id' => '77',
			'_vl_lesson_module_id' => '0',
		];

		( new LessonMetaBox() )->save( 44, Mockery::mock( 'WP_Post' ) );

		self::assertSame( [], $this->post_updates );
	}

	public function test_save_clears_post_parent_when_both_zero(): void {
		$this->lesson_parent = 77;

		$_POST = [
			self::NONCE_FIELD      => 'nonce-x',
			'_vl_lesson_course_id' => '0',
			'_vl_lesson_module_id' => '0',
		];

		( new LessonMetaBox() )->save( 44, Mockery::mock( 'WP_Post' ) );

		self::assertCount( 1, $this->post_updates );
		self::assertSame( 0, $this->post_updates[0]['post_parent'] );
	}

	public function test_save_rejects_course_pointing_at_non_course(): void {
		$this->lesson_parent = 77;

		$_POST = [
			self::NONCE_FIELD      => 'nonce-x',
			'_vl_lesson_course_id' => '88',
			'_vl_lesson_module_id' => '0',
		];

		( new LessonMetaBox() )->save( 44, Mockery::mock( 'WP_Post' ) );

		self::assertCount( 1, $this->post_updates );
		self::assertSame( 0, $this->post_updates[0]['post_parent'] );
	}
}
