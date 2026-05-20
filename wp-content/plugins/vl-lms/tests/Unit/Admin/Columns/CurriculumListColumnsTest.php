<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Admin\Columns;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Admin\Columns\CurriculumListColumns;
use WP_Query;

final class CurriculumListColumnsTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'absint' )->alias( static fn ( mixed $v ): int => max( 0, (int) $v ) );
		Functions\when( 'selected' )->alias(
			static fn ( mixed $a, mixed $b ): string => ( (string) $a === (string) $b ) ? ' selected="selected"' : ''
		);

		$_GET = [];
	}

	protected function tearDown(): void {
		$_GET = [];
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
		Filters\expectAdded( 'manage_vl_session_posts_columns' )->once();
		Actions\expectAdded( 'manage_vl_session_posts_custom_column' )->once();
		Actions\expectAdded( 'restrict_manage_posts' )->once();
		Actions\expectAdded( 'parse_query' )->once();

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

	public function test_session_columns_inserts_course_and_delivery_before_date(): void {
		$columns = [
			'cb'    => '',
			'title' => 'Title',
			'date'  => 'Date',
		];

		$result = ( new CurriculumListColumns() )->session_columns( $columns );

		self::assertSame(
			[ 'cb', 'title', 'vl_course', 'vl_session_delivery', 'date' ],
			array_keys( $result )
		);
		self::assertSame( 'Course', $result['vl_course'] );
		self::assertSame( 'Date of delivery', $result['vl_session_delivery'] );
	}

	public function test_render_session_column_course_outputs_parent_course_title(): void {
		Functions\when( 'get_post_field' )->alias(
			static function ( string $field, int $id ): int {
				return ( 'post_parent' === $field && 55 === $id ) ? 77 : 0;
			}
		);
		Functions\when( 'get_post_type' )->alias(
			static fn ( int $id ): string => 77 === $id ? 'vl_course' : ''
		);
		Functions\when( 'get_the_title' )->alias(
			static fn ( int $id ): string => 77 === $id ? 'Cohort Course' : ''
		);

		ob_start();
		( new CurriculumListColumns() )->render_session_column( 'vl_course', 55 );
		self::assertSame( 'Cohort Course', ob_get_clean() );
	}

	public function test_render_session_column_delivery_formats_scheduled_start(): void {
		Functions\when( 'get_post_meta' )->alias(
			static function ( int $id, string $key ): string {
				return ( 55 === $id && '_vl_session_scheduled_start' === $key )
					? '2026-06-01T15:30:00Z'
					: '';
			}
		);
		Functions\when( 'get_option' )->alias(
			static function ( string $name ): string {
				return match ( $name ) {
					'date_format' => 'Y-m-d',
					'time_format' => 'H:i',
					default       => '',
				};
			}
		);
		Functions\when( 'wp_date' )->alias(
			static fn ( string $format, int $ts ): string => gmdate( $format, $ts )
		);

		ob_start();
		( new CurriculumListColumns() )->render_session_column( 'vl_session_delivery', 55 );
		self::assertSame( '2026-06-01 15:30', ob_get_clean() );
	}

	public function test_render_session_column_delivery_outputs_dash_when_empty(): void {
		Functions\when( 'get_post_meta' )->justReturn( '' );

		ob_start();
		( new CurriculumListColumns() )->render_session_column( 'vl_session_delivery', 55 );
		self::assertSame( '—', ob_get_clean() );
	}

	public function test_render_session_column_delivery_outputs_dash_for_unparseable_value(): void {
		Functions\when( 'get_post_meta' )->justReturn( 'not-a-date' );

		ob_start();
		( new CurriculumListColumns() )->render_session_column( 'vl_session_delivery', 55 );
		self::assertSame( '—', ob_get_clean() );
	}

	public function test_render_module_course_filter_does_nothing_on_other_post_types(): void {
		ob_start();
		( new CurriculumListColumns() )->render_module_course_filter( 'post' );
		self::assertSame( '', ob_get_clean() );
	}

	public function test_render_module_course_filter_emits_dropdown_with_courses(): void {
		$_GET = [ 'vl_course_id' => '7' ];

		$columns = new class() extends CurriculumListColumns {
			/** @return array<int, string> */
			protected function all_course_options(): array {
				return [
					3 => 'Course Beta',
					7 => 'Course Alpha',
				];
			}
		};

		ob_start();
		$columns->render_module_course_filter( 'vl_module' );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( '<select name="vl_course_id"', $html );
		self::assertStringContainsString( '<option value="0">All courses</option>', $html );
		self::assertStringContainsString( '<option value="3"', $html );
		self::assertStringContainsString( 'Course Beta', $html );
		self::assertStringContainsString( '<option value="7" selected="selected">', $html );
		self::assertStringContainsString( 'Course Alpha', $html );
	}

	public function test_apply_module_course_filter_sets_post_parent_when_present(): void {
		$_GET = [ 'vl_course_id' => '42' ];

		Functions\when( 'is_admin' )->justReturn( true );

		$query = Mockery::mock( 'WP_Query' );
		$query->shouldReceive( 'is_main_query' )->andReturn( true );
		$query->shouldReceive( 'get' )->with( 'post_type' )->andReturn( 'vl_module' );
		$query->shouldReceive( 'set' )->once()->with( 'post_parent', 42 );

		assert( $query instanceof WP_Query );
		( new CurriculumListColumns() )->apply_module_course_filter( $query );
	}

	public function test_apply_module_course_filter_noop_when_param_absent(): void {
		Functions\when( 'is_admin' )->justReturn( true );

		$query = Mockery::mock( 'WP_Query' );
		$query->shouldReceive( 'is_main_query' )->andReturn( true );
		$query->shouldReceive( 'get' )->with( 'post_type' )->andReturn( 'vl_module' );
		$query->shouldNotReceive( 'set' );

		assert( $query instanceof WP_Query );
		( new CurriculumListColumns() )->apply_module_course_filter( $query );
	}

	public function test_apply_module_course_filter_noop_for_other_post_types(): void {
		$_GET = [ 'vl_course_id' => '42' ];

		Functions\when( 'is_admin' )->justReturn( true );

		$query = Mockery::mock( 'WP_Query' );
		$query->shouldReceive( 'is_main_query' )->andReturn( true );
		$query->shouldReceive( 'get' )->with( 'post_type' )->andReturn( 'vl_lesson' );
		$query->shouldNotReceive( 'set' );

		assert( $query instanceof WP_Query );
		( new CurriculumListColumns() )->apply_module_course_filter( $query );
	}

	public function test_apply_module_course_filter_noop_when_not_main_query(): void {
		$_GET = [ 'vl_course_id' => '42' ];

		Functions\when( 'is_admin' )->justReturn( true );

		$query = Mockery::mock( 'WP_Query' );
		$query->shouldReceive( 'is_main_query' )->andReturn( false );
		$query->shouldNotReceive( 'set' );

		assert( $query instanceof WP_Query );
		( new CurriculumListColumns() )->apply_module_course_filter( $query );
	}

	public function test_apply_module_course_filter_noop_outside_admin(): void {
		$_GET = [ 'vl_course_id' => '42' ];

		Functions\when( 'is_admin' )->justReturn( false );

		$query = Mockery::mock( 'WP_Query' );
		$query->shouldNotReceive( 'set' );

		assert( $query instanceof WP_Query );
		( new CurriculumListColumns() )->apply_module_course_filter( $query );
	}
}
