<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Admin\Columns;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Admin\Columns\CurriculumListColumns;

final class CurriculumListColumnsTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_boot_registers_filters_and_actions(): void {
		Filters\expectAdded( 'manage_vl_module_posts_columns' )->once();
		Actions\expectAdded( 'manage_vl_module_posts_custom_column' )->once();
		Filters\expectAdded( 'manage_vl_lesson_posts_columns' )->once();
		Actions\expectAdded( 'manage_vl_lesson_posts_custom_column' )->once();
		Filters\expectAdded( 'manage_vl_topic_posts_columns' )->once();
		Actions\expectAdded( 'manage_vl_topic_posts_custom_column' )->once();

		( new CurriculumListColumns() )->boot();
	}

	public function test_module_columns_inserts_course_and_lesson_count_before_date(): void {
		$columns = [
			'cb'     => '',
			'title'  => 'Title',
			'author' => 'Author',
			'date'   => 'Date',
		];

		$result = ( new CurriculumListColumns() )->module_columns( $columns );

		self::assertSame(
			[ 'cb', 'title', 'author', 'vl_course', 'vl_lesson_count', 'date' ],
			array_keys( $result )
		);
		self::assertSame( 'Course', $result['vl_course'] );
		self::assertSame( 'Lessons', $result['vl_lesson_count'] );
	}

	public function test_lesson_columns_inserts_course_module_and_topic_count(): void {
		$columns = [
			'cb'    => '',
			'title' => 'Title',
			'date'  => 'Date',
		];

		$result = ( new CurriculumListColumns() )->lesson_columns( $columns );

		self::assertSame(
			[ 'cb', 'title', 'vl_course', 'vl_module', 'vl_topic_count', 'date' ],
			array_keys( $result )
		);
		self::assertSame( 'Module', $result['vl_module'] );
		self::assertSame( 'Topics', $result['vl_topic_count'] );
	}

	public function test_topic_columns_inserts_course_and_lesson(): void {
		$columns = [
			'cb'    => '',
			'title' => 'Title',
			'date'  => 'Date',
		];

		$result = ( new CurriculumListColumns() )->topic_columns( $columns );

		self::assertSame(
			[ 'cb', 'title', 'vl_course', 'vl_lesson', 'date' ],
			array_keys( $result )
		);
	}

	public function test_columns_append_when_date_column_absent(): void {
		$columns = [
			'cb'    => '',
			'title' => 'Title',
		];

		$result = ( new CurriculumListColumns() )->topic_columns( $columns );

		self::assertSame(
			[ 'cb', 'title', 'vl_course', 'vl_lesson' ],
			array_keys( $result )
		);
	}

	public function test_render_module_column_course_outputs_parent_course_title(): void {
		Functions\when( 'get_post_field' )->alias(
			static function ( string $field, int $id ): int {
				return ( 'post_parent' === $field && 10 === $id ) ? 99 : 0;
			}
		);
		Functions\when( 'get_post_type' )->alias(
			static fn ( int $id ): string => 99 === $id ? 'vl_course' : ''
		);
		Functions\when( 'get_the_title' )->alias(
			static fn ( int $id ): string => 99 === $id ? 'PHP Basics' : ''
		);

		ob_start();
		( new CurriculumListColumns() )->render_module_column( 'vl_course', 10 );
		self::assertSame( 'PHP Basics', ob_get_clean() );
	}

	public function test_render_module_column_course_outputs_dash_when_parent_missing(): void {
		Functions\when( 'get_post_field' )->justReturn( 0 );
		Functions\when( 'get_post_type' )->justReturn( '' );
		Functions\when( 'get_the_title' )->justReturn( '' );

		ob_start();
		( new CurriculumListColumns() )->render_module_column( 'vl_course', 10 );
		self::assertSame( '—', ob_get_clean() );
	}

	public function test_render_lesson_column_course_walks_through_module_parent(): void {
		Functions\when( 'get_post_field' )->alias(
			static function ( string $field, int $id ): int {
				if ( 'post_parent' !== $field ) {
					return 0;
				}
				return match ( $id ) {
					42      => 7,   // Lesson 42 → Module 7.
					7       => 3,   // Module 7 → Course 3.
					default => 0,
				};
			}
		);
		Functions\when( 'get_post_type' )->alias(
			static function ( int $id ): string {
				return match ( $id ) {
					3       => 'vl_course',
					7       => 'vl_module',
					default => '',
				};
			}
		);
		Functions\when( 'get_the_title' )->alias(
			static fn ( int $id ): string => 3 === $id ? 'Advanced PHP' : ''
		);

		ob_start();
		( new CurriculumListColumns() )->render_lesson_column( 'vl_course', 42 );
		self::assertSame( 'Advanced PHP', ob_get_clean() );
	}

	public function test_render_lesson_column_module_returns_dash_for_module_less_courses(): void {
		Functions\when( 'get_post_field' )->alias(
			static function ( string $field, int $id ): int {
				return ( 'post_parent' === $field && 42 === $id ) ? 3 : 0;
			}
		);
		Functions\when( 'get_post_type' )->alias(
			static fn ( int $id ): string => 3 === $id ? 'vl_course' : ''
		);
		Functions\when( 'get_the_title' )->justReturn( '' );

		ob_start();
		( new CurriculumListColumns() )->render_lesson_column( 'vl_module', 42 );
		self::assertSame( '—', ob_get_clean() );
	}

	public function test_render_topic_column_course_resolves_through_lesson(): void {
		Functions\when( 'get_post_field' )->alias(
			static function ( string $field, int $id ): int {
				if ( 'post_parent' !== $field ) {
					return 0;
				}
				return match ( $id ) {
					100     => 42,  // Topic 100 → Lesson 42.
					42      => 3,   // Lesson 42 → Course 3.
					default => 0,
				};
			}
		);
		Functions\when( 'get_post_type' )->alias(
			static fn ( int $id ): string => 3 === $id ? 'vl_course' : ''
		);
		Functions\when( 'get_the_title' )->alias(
			static fn ( int $id ): string => 3 === $id ? 'Course Title' : ''
		);

		ob_start();
		( new CurriculumListColumns() )->render_topic_column( 'vl_course', 100 );
		self::assertSame( 'Course Title', ob_get_clean() );
	}

	public function test_render_topic_column_lesson_outputs_parent_lesson_title(): void {
		Functions\when( 'get_post_field' )->alias(
			static function ( string $field, int $id ): int {
				return ( 'post_parent' === $field && 100 === $id ) ? 42 : 0;
			}
		);
		Functions\when( 'get_post_type' )->alias(
			static fn ( int $id ): string => 42 === $id ? 'vl_lesson' : ''
		);
		Functions\when( 'get_the_title' )->alias(
			static fn ( int $id ): string => 42 === $id ? 'Intro Lesson' : ''
		);

		ob_start();
		( new CurriculumListColumns() )->render_topic_column( 'vl_lesson', 100 );
		self::assertSame( 'Intro Lesson', ob_get_clean() );
	}

	public function test_render_topic_column_course_outputs_dash_when_topic_has_no_lesson(): void {
		Functions\when( 'get_post_field' )->justReturn( 0 );

		ob_start();
		( new CurriculumListColumns() )->render_topic_column( 'vl_course', 100 );
		self::assertSame( '—', ob_get_clean() );
	}
}
