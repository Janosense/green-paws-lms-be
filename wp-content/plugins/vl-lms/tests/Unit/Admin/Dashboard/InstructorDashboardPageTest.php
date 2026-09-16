<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Admin\Dashboard;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Admin\Dashboard\CourseStatsQuery;
use VL\LMS\Repositories\CourseInstructorRepository;
use WP_Post;

/**
 * The page's first tests, added with its column extension points
 * (study-time Sprint 2 Step 2). They pin the arithmetic a listener depends
 * on — one header call per render, one cell call per row, with that row's
 * course id — and that an unhooked page renders exactly as before.
 */
final class InstructorDashboardPageTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'get_edit_post_link' )->alias( static fn ( int $id ): string => '/wp-admin/post.php?post=' . $id );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function course( int $id, string $title ): WP_Post {
		$post             = Mockery::mock( 'WP_Post' );
		$post->ID         = $id;
		$post->post_title = $title;
		$post->post_name  = 'course-' . $id;
		return $post;
	}

	/**
	 * @param list<WP_Post>   $courses
	 * @param array<int, int> $enrollments
	 * @param array<int, int> $completions
	 */
	private function page( array $courses, array $enrollments = [], array $completions = [] ): TestableInstructorDashboardPage {
		$stats = Mockery::mock( CourseStatsQuery::class );
		$stats->shouldReceive( 'enrollment_count_by_course' )->andReturn( $enrollments );
		$stats->shouldReceive( 'completion_count_by_course' )->andReturn( $completions );

		$instructors = Mockery::mock( CourseInstructorRepository::class );

		$page = new TestableInstructorDashboardPage( $instructors, $stats );
		return $page->with_courses( $courses );
	}

	private function render( TestableInstructorDashboardPage $page ): string {
		ob_start();
		$page->render();
		return (string) ob_get_clean();
	}

	public function test_the_header_hook_fires_once_and_the_cell_hook_once_per_course(): void {
		Actions\expectDone( 'vl_lms_admin_instructor_dashboard_columns' )->once()->withNoArgs();
		Actions\expectDone( 'vl_lms_admin_instructor_dashboard_cells' )->twice();

		$output = $this->render(
			$this->page(
				[ $this->course( 101, 'CMS 101' ), $this->course( 202, 'Анестезія' ) ]
			)
		);

		self::assertStringContainsString( 'CMS 101', $output );
		self::assertStringContainsString( 'Анестезія', $output );
	}

	public function test_each_cell_hook_carries_the_course_id_of_its_own_row(): void {
		$seen = [];
		Actions\expectDone( 'vl_lms_admin_instructor_dashboard_cells' )
			->twice()
			->whenHappen(
				static function ( int $course_id ) use ( &$seen ): void {
					$seen[] = $course_id;
				}
			);

		$this->render(
			$this->page(
				[ $this->course( 101, 'CMS 101' ), $this->course( 202, 'Анестезія' ) ]
			)
		);

		self::assertSame( [ 101, 202 ], $seen, 'the cells arrive in row order, each with its own course' );
	}

	public function test_an_instructor_without_courses_offers_no_columns_at_all(): void {
		// There is no table, so there is nothing to extend — a listener must
		// not be asked for a header it could never pair with a cell.
		Actions\expectDone( 'vl_lms_admin_instructor_dashboard_columns' )->never();
		Actions\expectDone( 'vl_lms_admin_instructor_dashboard_cells' )->never();

		$output = $this->render( $this->page( [] ) );

		self::assertStringContainsString( 'Ви ще не створили жодного курсу.', $output );
	}

	public function test_the_table_keeps_four_columns_while_nothing_is_hooked(): void {
		// The header / cell counts a listener must keep balanced.
		$output = $this->render(
			$this->page(
				[ $this->course( 101, 'CMS 101' ) ],
				[ 101 => 7 ],
				[ 101 => 3 ]
			)
		);

		// `<th>` closed, because a bare `<th` prefix also matches `<thead>`;
		// `<td` open, because the course cell starts as `<td><a …`.
		self::assertSame( 4, substr_count( $output, '<th>' ) );
		self::assertSame( 4, substr_count( $output, '<td' ) );
		self::assertStringContainsString( '<td>7</td>', $output );
		self::assertStringContainsString( '<td>3</td>', $output );
	}
}
