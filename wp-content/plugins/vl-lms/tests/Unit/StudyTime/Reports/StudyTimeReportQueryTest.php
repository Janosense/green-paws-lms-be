<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\StudyTime\Reports;

use Brain\Monkey;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Learn\Progression\CurriculumOrder;
use VL\LMS\Learn\Progression\CurriculumStop;
use VL\LMS\StudyTime\Reports\StudyTimeReportQuery;

/**
 * The `$wpdb` double substitutes binds in source order, so every assertion
 * reads the SQL MySQL would receive (`docs/TESTING.md`), and answers each
 * read by the table its SQL names — the query hits three tables per call.
 */
final class StudyTimeReportQueryTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const string LEDGER     = 'wp_vl_study_time';
	private const string ATTEMPTS   = 'wp_vl_quiz_attempts';
	private const string ATTENDANCE = 'wp_vl_session_attendance';

	/**
	 * @var Mockery\MockInterface
	 */
	private $wpdb;

	/**
	 * Result sets keyed by the table their query names.
	 *
	 * @var array<string, mixed>
	 */
	private array $results = [];

	/**
	 * Every SQL string the query prepared, binds substituted, in order.
	 *
	 * @var list<string>
	 */
	private array $prepared = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WP core constant shim for tests.
		defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

		$this->wpdb         = Mockery::mock();
		$this->wpdb->prefix = 'wp_';
		$this->wpdb->posts  = 'wp_posts';

		$this->wpdb->shouldReceive( 'prepare' )
			->andReturnUsing(
				function ( string $sql, ...$args ): string {
					if ( 1 === count( $args ) && is_array( $args[0] ) ) {
						$args = $args[0];
					}
					$substituted      = (string) preg_replace_callback(
						'/%[ds]/',
						static function () use ( &$args ): string {
							$next = array_shift( $args );
							return is_string( $next ) ? "'" . $next . "'" : (string) $next;
						},
						$sql
					);
					$this->prepared[] = $substituted;
					return $substituted;
				}
			);

		$this->wpdb->shouldReceive( 'get_results' )
			->andReturnUsing( fn ( string $sql ): array => $this->answer_for( $sql ) );

		$this->wpdb->shouldReceive( 'get_var' )
			->andReturnUsing( fn ( string $sql ) => $this->answer_for( $sql ) );

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test double for $wpdb.
		$GLOBALS['wpdb'] = $this->wpdb;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		$this->results  = [];
		$this->prepared = [];
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @return mixed
	 */
	private function answer_for( string $sql ) {
		foreach ( $this->results as $table => $result ) {
			if ( str_contains( $sql, $table ) ) {
				return $result;
			}
		}
		return str_contains( $sql, 'COALESCE' ) ? 0 : [];
	}

	/**
	 * @param list<array<string, mixed>> $ledger_rows
	 */
	private function seed( array $ledger_rows = [], int $quiz_seconds = 0, int $session_seconds = 0 ): void {
		$this->results = [
			self::LEDGER     => $ledger_rows,
			self::ATTEMPTS   => $quiz_seconds,
			self::ATTENDANCE => $session_seconds,
		];
	}

	/**
	 * @param array<int, string> $lessons `id => title`, in curriculum order
	 */
	private function query_with_lessons( array $lessons ): StudyTimeReportQuery {
		$stops = [];
		foreach ( $lessons as $id => $title ) {
			$stops[] = new CurriculumStop( CurriculumStop::KIND_LESSON, $id, 'slug-' . $id, $title );
			// A quiz stop between lessons: the report must ignore every kind
			// that is not a lesson rather than assume a lesson-only list.
			$stops[] = new CurriculumStop( CurriculumStop::KIND_QUIZ, $id + 900 );
		}

		$order = Mockery::mock( CurriculumOrder::class );
		$order->shouldReceive( 'for_course' )->andReturn( $stops );

		return new StudyTimeReportQuery( $order );
	}

	/**
	 * @param array<string, mixed> $overrides
	 * @return array<string, mixed>
	 */
	private function ledger_row( array $overrides ): array {
		return array_merge(
			[
				'lesson_id' => 10,
				'kind'      => 'video',
				'seconds'   => 0,
			],
			$overrides
		);
	}

	public function test_it_sums_the_four_kinds_from_their_three_tables(): void {
		$this->seed(
			[
				$this->ledger_row(
					[
						'kind'    => 'video',
						'seconds' => 600,
					]
				),
				$this->ledger_row(
					[
						'kind'    => 'reading',
						'seconds' => 300,
					]
				),
			],
			quiz_seconds: 120,
			session_seconds: 3600
		);

		$report = $this->query_with_lessons( [ 10 => 'Епідуральна анестезія' ] )->for_enrollment( 7, 568 );

		$this->assertSame( 600, $report['video'] );
		$this->assertSame( 300, $report['reading'] );
		$this->assertSame( 120, $report['quiz'] );
		$this->assertSame( 3600, $report['session'] );
		$this->assertSame( 4620, $report['total'], 'the course total is the four kinds added up' );
	}

	public function test_a_topics_seconds_are_folded_onto_its_lesson_in_sql(): void {
		// The ledger stores the topic; the report shows the lesson. The fold
		// happens in the statement, because a CurriculumStop has no parent.
		$this->seed(
			[
				$this->ledger_row(
					[
						'lesson_id' => 10,
						'kind'      => 'reading',
						'seconds'   => 60,
					]
				),
				$this->ledger_row(
					[
						'lesson_id' => 10,
						'kind'      => 'reading',
						'seconds'   => 90,
					]
				),
			]
		);

		$report = $this->query_with_lessons( [ 10 => 'Епідуральна анестезія' ] )->for_enrollment( 7, 568 );

		$this->assertSame( 150, $report['lessons'][0]['total'] );
		$this->assertStringContainsString(
			"CASE WHEN s.entity_type = 'topic' THEN p.post_parent ELSE s.entity_id END AS lesson_id",
			$this->prepared[0]
		);
		$this->assertStringContainsString( 'LEFT JOIN wp_posts p ON p.ID = s.entity_id', $this->prepared[0] );
	}

	public function test_lessons_follow_the_curriculum_order_not_the_result_order(): void {
		$this->seed(
			[
				$this->ledger_row(
					[
						'lesson_id' => 30,
						'kind'      => 'video',
						'seconds'   => 30,
					]
				),
				$this->ledger_row(
					[
						'lesson_id' => 10,
						'kind'      => 'video',
						'seconds'   => 60,
					]
				),
			]
		);

		$report = $this->query_with_lessons(
			[
				10 => 'Перший',
				30 => 'Третій',
			]
		)->for_enrollment( 7, 568 );

		$this->assertSame( [ 10, 30 ], array_column( $report['lessons'], 'lesson_id' ) );
		$this->assertSame( [ 'Перший', 'Третій' ], array_column( $report['lessons'], 'title' ) );
	}

	public function test_a_lesson_without_time_is_left_out_of_the_table(): void {
		$this->seed(
			[
				$this->ledger_row(
					[
						'lesson_id' => 10,
						'seconds'   => 60,
					]
				),
			]
		);

		$report = $this->query_with_lessons(
			[
				10 => 'Вивчений',
				20 => 'Не відкривали',
			]
		)->for_enrollment( 7, 568 );

		$this->assertCount( 1, $report['lessons'], 'a course-length table of zeros would bury the answer' );
		$this->assertSame( 10, $report['lessons'][0]['lesson_id'] );
	}

	public function test_an_orphan_row_keeps_its_seconds_in_the_total_but_appears_in_no_lesson(): void {
		// A deleted entity leaves post_parent NULL -> lesson_id 0; a lesson
		// dropped from the curriculum is simply not among the stops.
		$this->seed(
			[
				$this->ledger_row(
					[
						'lesson_id' => 0,
						'kind'      => 'reading',
						'seconds'   => 45,
					]
				),
				$this->ledger_row(
					[
						'lesson_id' => 99,
						'kind'      => 'video',
						'seconds'   => 15,
					]
				),
				$this->ledger_row(
					[
						'lesson_id' => 10,
						'kind'      => 'video',
						'seconds'   => 60,
					]
				),
			]
		);

		$report = $this->query_with_lessons( [ 10 => 'Вивчений' ] )->for_enrollment( 7, 568 );

		$this->assertSame( 75, $report['video'] );
		$this->assertSame( 45, $report['reading'] );
		$this->assertSame( 120, $report['total'], 'the arithmetic never loses a second' );
		$this->assertSame( [ 10 ], array_column( $report['lessons'], 'lesson_id' ) );
	}

	public function test_only_finished_attempts_carry_quiz_time(): void {
		$this->seed( quiz_seconds: 480 );

		$this->query_with_lessons( [] )->for_enrollment( 7, 568 );

		$attempts_sql = $this->sql_naming( self::ATTEMPTS );
		$this->assertStringContainsString( "status IN ('submitted', 'expired')", $attempts_sql );
		$this->assertStringContainsString( 'user_id = 7', $attempts_sql );
		$this->assertStringContainsString( 'course_id = 568', $attempts_sql );
	}

	public function test_quiz_time_from_before_a_progress_reset_still_counts(): void {
		// Study time is history: the reset keeps it. This read therefore
		// carries none of the epoch machinery the gate-feeding reads use.
		$this->seed( quiz_seconds: 900 );

		$report = $this->query_with_lessons( [] )->for_enrollment( 7, 568 );

		$this->assertSame( 900, $report['quiz'] );
		$attempts_sql = $this->sql_naming( self::ATTEMPTS );
		$this->assertStringNotContainsString( 'progress_reset_at', $attempts_sql );
		$this->assertStringNotContainsString( 'vl_enrollments', $attempts_sql );
	}

	public function test_session_time_is_scoped_to_the_courses_own_sessions(): void {
		$this->seed( session_seconds: 5400 );

		$report = $this->query_with_lessons( [] )->for_enrollment( 7, 568 );

		$this->assertSame( 5400, $report['session'] );
		$attendance_sql = $this->sql_naming( self::ATTENDANCE );
		$this->assertStringContainsString( 'INNER JOIN wp_posts p ON p.ID = a.session_id', $attendance_sql );
		$this->assertStringContainsString( "p.post_type = 'vl_session'", $attendance_sql );
		$this->assertStringContainsString( 'p.post_parent = 568', $attendance_sql );
		$this->assertStringNotContainsString(
			'post_status',
			$attendance_sql,
			'attendance is a historical fact; a session later moved to draft must not erase it'
		);
	}

	public function test_an_open_attendance_row_contributes_nothing_instead_of_nulling_the_total(): void {
		// SUM() over only-NULL durations is NULL; COALESCE is what keeps the
		// figure an integer.
		$this->seed( session_seconds: 0 );

		$report = $this->query_with_lessons( [] )->for_enrollment( 7, 568 );

		$this->assertSame( 0, $report['session'] );
		$this->assertStringContainsString( 'COALESCE(SUM(a.duration_seconds), 0)', $this->sql_naming( self::ATTENDANCE ) );
	}

	public function test_a_learner_with_no_rows_anywhere_reads_as_zeros(): void {
		$this->seed();

		$report = $this->query_with_lessons( [ 10 => 'Урок' ] )->for_enrollment( 7, 568 );

		$this->assertSame(
			[
				'total'   => 0,
				'video'   => 0,
				'reading' => 0,
				'quiz'    => 0,
				'session' => 0,
				'lessons' => [],
			],
			$report
		);
	}

	private function sql_naming( string $table ): string {
		foreach ( $this->prepared as $sql ) {
			if ( str_contains( $sql, $table ) ) {
				return $sql;
			}
		}
		$this->fail( 'No statement named ' . $table );
	}
}
