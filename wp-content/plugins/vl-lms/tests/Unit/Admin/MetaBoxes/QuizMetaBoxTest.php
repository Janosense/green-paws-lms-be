<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Admin\MetaBoxes;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Admin\MetaBoxes\QuizMetaBox;
use WP_Post;

final class QuizMetaBoxTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const string NONCE_FIELD = 'vl_lms_vl_lms_quiz_nonce';

	/** @var list<array{0: int, 1: string, 2: mixed}> */
	private array $writes = [];

	/** @var list<array<string, mixed>> */
	private array $parent_updates = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_post_type' )->justReturn( 'vl_quiz' );

		$this->writes         = [];
		$this->parent_updates = [];
		$writes               = &$this->writes;
		$parent_updates       = &$this->parent_updates;
		Functions\when( 'update_post_meta' )->alias(
			static function ( int $id, string $key, mixed $value ) use ( &$writes ): bool {
				$writes[] = [ $id, $key, $value ];
				return true;
			}
		);
		Functions\when( 'wp_update_post' )->alias(
			static function ( array $args ) use ( &$parent_updates ): int {
				$parent_updates[] = $args;
				return (int) ( $args['ID'] ?? 0 );
			}
		);

		$_POST = [];
	}

	protected function tearDown(): void {
		$_POST = [];
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_save_clamps_passing_threshold_above_100(): void {
		$_POST = [
			self::NONCE_FIELD            => 'nonce-x',
			'_vl_quiz_passing_threshold' => '999',
		];

		$post = Mockery::mock( 'WP_Post' );
		assert( $post instanceof WP_Post );
		( new QuizMetaBox() )->save( 9, $post );

		$threshold_writes = array_values(
			array_filter(
				$this->writes,
				static fn ( array $row ): bool => '_vl_quiz_passing_threshold' === $row[1]
			)
		);
		self::assertCount( 1, $threshold_writes );
		self::assertSame( 100, $threshold_writes[0][2] );
	}

	public function test_save_does_not_touch_parent_when_picker_absent(): void {
		$_POST = [
			self::NONCE_FIELD            => 'nonce-x',
			'_vl_quiz_passing_threshold' => '50',
		];

		$post = Mockery::mock( 'WP_Post' );
		assert( $post instanceof WP_Post );
		( new QuizMetaBox() )->save( 9, $post );

		self::assertSame( [], $this->parent_updates );
	}

	public function test_save_attaches_quiz_directly_to_course(): void {
		Functions\when( 'get_post_type' )->alias(
			static fn ( int $id ): string => 9 === $id ? 'vl_quiz' : 'vl_course'
		);
		Functions\when( 'get_post_field' )->justReturn( 0 );

		$_POST = [
			self::NONCE_FIELD           => 'nonce-x',
			'_vl_quiz_parent_course_id' => '42',
			'_vl_quiz_parent_unit_id'   => '0',
		];

		$post = Mockery::mock( 'WP_Post' );
		assert( $post instanceof WP_Post );
		( new QuizMetaBox() )->save( 9, $post );

		self::assertCount( 1, $this->parent_updates );
		self::assertSame( 9, $this->parent_updates[0]['ID'] );
		self::assertSame( 42, $this->parent_updates[0]['post_parent'] );
	}

	public function test_save_attaches_quiz_to_unit_inside_the_chosen_course(): void {
		// 9 = the quiz, 42 = the course, 50 = a module whose parent is 42.
		Functions\when( 'get_post_type' )->alias(
			static function ( int $id ): string {
				return match ( $id ) {
					9       => 'vl_quiz',
					50      => 'vl_module',
					default => 'vl_course',
				};
			}
		);
		Functions\when( 'get_post_field' )->alias(
			static fn ( string $field, int $id ): int => 50 === $id ? 42 : 0
		);

		$_POST = [
			self::NONCE_FIELD           => 'nonce-x',
			'_vl_quiz_parent_course_id' => '42',
			'_vl_quiz_parent_unit_id'   => '50',
		];

		$post = Mockery::mock( 'WP_Post' );
		assert( $post instanceof WP_Post );
		( new QuizMetaBox() )->save( 9, $post );

		self::assertCount( 1, $this->parent_updates );
		self::assertSame( 50, $this->parent_updates[0]['post_parent'] );
	}

	public function test_save_falls_back_to_course_when_unit_belongs_to_a_different_course(): void {
		// Unit 50 is a module under course 99, but the editor chose course 42.
		Functions\when( 'get_post_type' )->alias(
			static function ( int $id ): string {
				return match ( $id ) {
					9       => 'vl_quiz',
					50      => 'vl_module',
					default => 'vl_course',
				};
			}
		);
		Functions\when( 'get_post_field' )->alias(
			static fn ( string $field, int $id ): int => 50 === $id ? 99 : 0
		);

		$_POST = [
			self::NONCE_FIELD           => 'nonce-x',
			'_vl_quiz_parent_course_id' => '42',
			'_vl_quiz_parent_unit_id'   => '50',
		];

		$post = Mockery::mock( 'WP_Post' );
		assert( $post instanceof WP_Post );
		( new QuizMetaBox() )->save( 9, $post );

		self::assertCount( 1, $this->parent_updates );
		self::assertSame( 42, $this->parent_updates[0]['post_parent'] );
	}
}
