<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Repositories;

use Brain\Monkey;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Quiz\QuizAttempt;
use VL\LMS\Domain\Quiz\QuizAttemptStatus;
use VL\LMS\Repositories\QuizAttemptRepository;

final class QuizAttemptRepositoryTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private QuizAttemptRepository $repo;

	/**
	 * @var Mockery\MockInterface
	 */
	private $wpdb;

	/** @var list<\DateTimeImmutable> */
	private array $clock_ticks = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WP core constant shim for tests.
		defined( 'ABSPATH' ) || define( 'ABSPATH', sys_get_temp_dir() . '/' );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WP core constant shim for tests.
		defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

		$this->wpdb         = Mockery::mock();
		$this->wpdb->prefix = 'wp_';
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test double for $wpdb.
		$GLOBALS['wpdb'] = $this->wpdb;

		$this->clock_ticks = [];
		$ticks             = &$this->clock_ticks;
		$this->repo        = new QuizAttemptRepository(
			static function () use ( &$ticks ): \DateTimeImmutable {
				if ( [] === $ticks ) {
					return new \DateTimeImmutable( '2026-04-28 10:00:00', new \DateTimeZone( 'UTC' ) );
				}
				$next = array_shift( $ticks );
				return $next;
			}
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function row(
		int $id = 17,
		int $user_id = 5,
		int $quiz_id = 101,
		int $course_id = 7,
		string $status = 'in_progress',
		?int $score = null,
		?int $passed = null,
		?string $submitted_at = null
	): array {
		return [
			'id'                 => (string) $id,
			'user_id'            => (string) $user_id,
			'quiz_id'            => (string) $quiz_id,
			'course_id'          => (string) $course_id,
			'status'             => $status,
			'started_at'         => '2026-04-28 10:00:00',
			'submitted_at'       => $submitted_at,
			'time_limit_seconds' => '600',
			'time_taken_seconds' => null,
			'score'              => null === $score ? null : (string) $score,
			'max_score'          => '100',
			'passed'             => null === $passed ? null : (string) $passed,
			'passing_threshold'  => '70',
			'question_order'     => '[201,202,203]',
			'created_at'         => '2026-04-28 10:00:00',
			'updated_at'         => '2026-04-28 10:00:00',
		];
	}

	private static function utc( string $value ): \DateTimeImmutable {
		return new \DateTimeImmutable( $value, new \DateTimeZone( 'UTC' ) );
	}

	public function test_find_returns_quiz_attempt_for_existing_id(): void {
		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( self::row( 17 ) );

		$result = $this->repo->find( 17 );

		self::assertInstanceOf( QuizAttempt::class, $result );
		self::assertSame( 17, $result->id );
	}

	public function test_find_returns_null_when_no_row(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )->andReturn( null );

		self::assertNull( $this->repo->find( 999 ) );
	}

	public function test_find_active_filters_by_in_progress_status(): void {
		$captured_args = [];

		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->andReturnUsing(
				function ( string $sql, ...$args ) use ( &$captured_args ): string {
					$captured_args = $args;
					return $sql;
				}
			);
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( self::row( 17 ) );

		$result = $this->repo->find_active_for_user_in_quiz( 5, 101 );

		self::assertInstanceOf( QuizAttempt::class, $result );
		self::assertSame( [ 5, 101, 'in_progress' ], $captured_args );
	}

	public function test_count_for_user_in_quiz_returns_int_from_get_var(): void {
		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '4' );

		self::assertSame( 4, $this->repo->count_for_user_in_quiz( 5, 101 ) );
	}

	public function test_count_for_user_in_quiz_returns_zero_when_get_var_null(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( null );

		self::assertSame( 0, $this->repo->count_for_user_in_quiz( 5, 101 ) );
	}

	public function test_list_for_user_in_quiz_hydrates_rows(): void {
		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_results' )
			->once()
			->andReturn( [ self::row( 1 ), self::row( 2 ) ] );

		$rows = $this->repo->list_for_user_in_quiz( 5, 101 );

		self::assertCount( 2, $rows );
		self::assertSame( 1, $rows[0]->id );
		self::assertSame( 2, $rows[1]->id );
	}

	public function test_find_best_score_filters_to_submitted_only(): void {
		$captured_args = [];

		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->andReturnUsing(
				function ( string $sql, ...$args ) use ( &$captured_args ): string {
					$captured_args = $args;
					return $sql;
				}
			);
		$this->wpdb->shouldReceive( 'get_row' )
			->once()
			->andReturn( self::row( 17, 5, 101, 7, 'submitted', 90, 1, '2026-04-28 10:30:00' ) );

		$result = $this->repo->find_best_score_for_user_in_quiz( 5, 101 );

		self::assertInstanceOf( QuizAttempt::class, $result );
		self::assertSame( 'submitted', $captured_args[2] );
	}

	public function test_find_best_score_returns_null_when_only_in_progress_attempts_exist(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )->andReturn( null );

		self::assertNull( $this->repo->find_best_score_for_user_in_quiz( 5, 101 ) );
	}

	public function test_list_passed_for_user_in_course_filters_passed_and_submitted(): void {
		$captured_sql  = null;
		$captured_args = [];

		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->andReturnUsing(
				function ( string $sql, ...$args ) use ( &$captured_sql, &$captured_args ): string {
					$captured_sql  = $sql;
					$captured_args = $args;
					return $sql;
				}
			);
		$this->wpdb->shouldReceive( 'get_results' )
			->once()
			->andReturn( [ self::row( 17, 5, 101, 7, 'submitted', 90, 1, '2026-04-28 10:30:00' ) ] );

		$rows = $this->repo->list_passed_for_user_in_course( 5, 7 );

		self::assertCount( 1, $rows );
		self::assertTrue( $rows[0]->passed );
		self::assertSame( [ 5, 7, 'submitted' ], $captured_args );
		self::assertStringContainsString( 'passed = 1', (string) $captured_sql );
	}

	public function test_attempt_summary_for_users_short_circuits_on_empty_input(): void {
		$this->wpdb->shouldNotReceive( 'prepare' );
		$this->wpdb->shouldNotReceive( 'get_results' );

		self::assertSame( [], $this->repo->attempt_summary_for_users( [] ) );
	}

	public function test_attempt_summary_for_users_groups_rows_by_user(): void {
		$captured_sql  = null;
		$captured_args = [];

		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->andReturnUsing(
				function ( string $sql, ...$args ) use ( &$captured_sql, &$captured_args ): string {
					$captured_sql  = $sql;
					$captured_args = $args;
					return $sql;
				}
			);
		$this->wpdb->shouldReceive( 'get_results' )
			->once()
			->andReturn(
				[
					[
						'user_id'        => '5',
						'attempts'       => '4',
						'graded'         => '3',
						'passed'         => '1',
						'quizzes'        => '2',
						'quizzes_passed' => '1',
					],
					[
						'user_id'        => '6',
						'attempts'       => '1',
						'graded'         => '0',
						'passed'         => '0',
						'quizzes'        => '1',
						'quizzes_passed' => '0',
					],
				]
			);

		$out = $this->repo->attempt_summary_for_users( [ 5, 6 ] );

		self::assertSame(
			[
				'attempts'       => 4,
				'graded'         => 3,
				'passed'         => 1,
				'quizzes'        => 2,
				'quizzes_passed' => 1,
			],
			$out[5]
		);
		self::assertSame( 1, $out[6]['attempts'] );
		self::assertSame( 0, $out[6]['graded'] );

		// The `%s` for status sits in the SELECT list, ahead of the `%d` run
		// in the IN clause, so the status must bind first.
		self::assertSame( [ [ 'in_progress', 5, 6 ] ], $captured_args );
		self::assertStringContainsString( 'user_id IN (%d, %d)', (string) $captured_sql );
		self::assertStringContainsString( 'GROUP BY user_id', (string) $captured_sql );
		self::assertStringContainsString( 'COUNT(DISTINCT quiz_id)', (string) $captured_sql );
	}

	/**
	 * `$wpdb->prepare()` binds positionally, so asserting the args array
	 * alone cannot tell a correct ordering from a shifted one. Substituting
	 * the placeholders in source order — the way `prepare` does — turns a
	 * mis-ordered bind into a visibly wrong query: the status string would
	 * land in the IN list and a user id would be compared against `status`.
	 */
	public function test_attempt_summary_for_users_binds_placeholders_in_source_order(): void {
		$prepared = null;

		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->andReturnUsing(
				function ( string $sql, ...$args ) use ( &$prepared ): string {
					$values = is_array( $args[0] ?? null ) ? $args[0] : $args;
					$prepared = preg_replace_callback(
						'/%[ds]/',
						static function () use ( &$values ): string {
							$next = array_shift( $values );
							return is_string( $next ) ? "'" . $next . "'" : (string) $next;
						},
						$sql
					);
					return (string) $prepared;
				}
			);
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( [] );

		$this->repo->attempt_summary_for_users( [ 5, 6 ] );

		self::assertStringContainsString( "status != 'in_progress'", (string) $prepared );
		self::assertStringContainsString( 'user_id IN (5, 6)', (string) $prepared );
	}

	public function test_attempt_summary_for_users_omits_users_without_attempts(): void {
		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( [] );

		$out = $this->repo->attempt_summary_for_users( [ 5, 6 ] );

		self::assertSame( [], $out );
	}

	public function test_attempt_summary_for_users_tolerates_a_non_array_result(): void {
		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( null );

		self::assertSame( [], $this->repo->attempt_summary_for_users( [ 5 ] ) );
	}

	public function test_find_passed_final_exam_targets_specific_quiz(): void {
		$captured_args = [];

		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->andReturnUsing(
				function ( string $sql, ...$args ) use ( &$captured_args ): string {
					$captured_args = $args;
					return $sql;
				}
			);
		$this->wpdb->shouldReceive( 'get_row' )
			->once()
			->andReturn( self::row( 17, 5, 101, 7, 'submitted', 90, 1, '2026-04-28 10:30:00' ) );

		$result = $this->repo->find_passed_final_exam_for_user_in_course( 5, 7, 101 );

		self::assertInstanceOf( QuizAttempt::class, $result );
		self::assertSame( [ 5, 7, 101, 'submitted' ], $captured_args );
	}

	public function test_insert_writes_row_with_clock_stamped_audit_columns(): void {
		$this->clock_ticks = [
			self::utc( '2026-04-28 10:00:00' ),
		];

		$captured_data = null;

		$this->wpdb->shouldReceive( 'insert' )
			->once()
			->andReturnUsing(
				function ( string $table, array $data ) use ( &$captured_data ): int {
					$captured_data = $data;
					return 1;
				}
			);
		$this->wpdb->insert_id = 42;

		$attempt = new QuizAttempt(
			0,
			5,
			101,
			7,
			QuizAttemptStatus::IN_PROGRESS,
			self::utc( '2026-04-28 10:00:00' ),
			null,
			600,
			null,
			null,
			100,
			null,
			70,
			[ 201, 202, 203 ],
			self::utc( '2026-04-28 10:00:00' ),
			self::utc( '2026-04-28 10:00:00' )
		);

		$id = $this->repo->insert( $attempt );

		self::assertSame( 42, $id );
		self::assertArrayNotHasKey( 'id', $captured_data );
		self::assertSame( 5, $captured_data['user_id'] );
		self::assertSame( 'in_progress', $captured_data['status'] );
		self::assertSame( 100, $captured_data['max_score'] );
		self::assertSame( 70, $captured_data['passing_threshold'] );
		self::assertSame( '[201,202,203]', $captured_data['question_order'] );
		self::assertSame( '2026-04-28 10:00:00', $captured_data['created_at'] );
		self::assertSame( '2026-04-28 10:00:00', $captured_data['updated_at'] );
	}

	public function test_update_status_sets_status_and_optionally_submitted_at(): void {
		$this->clock_ticks = [
			self::utc( '2026-04-28 10:30:00' ),
		];

		$captured_data  = null;
		$captured_where = null;

		$this->wpdb->shouldReceive( 'update' )
			->once()
			->andReturnUsing(
				function ( string $table, array $data, array $where ) use ( &$captured_data, &$captured_where ): int {
					$captured_data  = $data;
					$captured_where = $where;
					return 1;
				}
			);

		$ok = $this->repo->update_status( 17, QuizAttemptStatus::EXPIRED, self::utc( '2026-04-28 10:30:00' ) );

		self::assertTrue( $ok );
		self::assertSame( [ 'id' => 17 ], $captured_where );
		self::assertSame( 'expired', $captured_data['status'] );
		self::assertSame( '2026-04-28 10:30:00', $captured_data['submitted_at'] );
		self::assertSame( '2026-04-28 10:30:00', $captured_data['updated_at'] );
	}

	public function test_update_status_omits_submitted_at_when_null(): void {
		$this->clock_ticks = [ self::utc( '2026-04-28 10:30:00' ) ];

		$captured_data = null;

		$this->wpdb->shouldReceive( 'update' )
			->once()
			->andReturnUsing(
				function ( string $table, array $data ) use ( &$captured_data ): int {
					$captured_data = $data;
					return 1;
				}
			);

		$this->repo->update_status( 17, QuizAttemptStatus::ABANDONED );

		self::assertArrayNotHasKey( 'submitted_at', $captured_data );
		self::assertSame( 'abandoned', $captured_data['status'] );
	}

	public function test_update_final_writes_all_five_outcome_columns_atomically(): void {
		$this->clock_ticks = [ self::utc( '2026-04-28 10:30:00' ) ];

		$captured_data  = null;
		$captured_where = null;

		$this->wpdb->shouldReceive( 'update' )
			->once()
			->andReturnUsing(
				function ( string $table, array $data, array $where ) use ( &$captured_data, &$captured_where ): int {
					$captured_data  = $data;
					$captured_where = $where;
					return 1;
				}
			);

		$ok = $this->repo->update_final(
			17,
			85,
			true,
			1800,
			self::utc( '2026-04-28 10:30:00' ),
			QuizAttemptStatus::SUBMITTED
		);

		self::assertTrue( $ok );
		self::assertSame( [ 'id' => 17 ], $captured_where );

		// All five outcome columns plus updated_at, in a single update call.
		self::assertSame( 85, $captured_data['score'] );
		self::assertSame( 1, $captured_data['passed'] );
		self::assertSame( 1800, $captured_data['time_taken_seconds'] );
		self::assertSame( '2026-04-28 10:30:00', $captured_data['submitted_at'] );
		self::assertSame( 'submitted', $captured_data['status'] );
		self::assertSame( '2026-04-28 10:30:00', $captured_data['updated_at'] );
	}

	public function test_update_final_persists_failed_attempt(): void {
		$this->clock_ticks = [ self::utc( '2026-04-28 10:30:00' ) ];

		$captured_data = null;

		$this->wpdb->shouldReceive( 'update' )
			->once()
			->andReturnUsing(
				function ( string $table, array $data ) use ( &$captured_data ): int {
					$captured_data = $data;
					return 1;
				}
			);

		$this->repo->update_final(
			17,
			40,
			false,
			900,
			self::utc( '2026-04-28 10:15:00' ),
			QuizAttemptStatus::SUBMITTED
		);

		self::assertSame( 0, $captured_data['passed'] );
		self::assertSame( 40, $captured_data['score'] );
	}

	public function test_update_final_returns_false_when_wpdb_fails(): void {
		$this->clock_ticks = [ self::utc( '2026-04-28 10:30:00' ) ];

		$this->wpdb->shouldReceive( 'update' )->once()->andReturn( false );

		$ok = $this->repo->update_final(
			17,
			85,
			true,
			1800,
			self::utc( '2026-04-28 10:30:00' ),
			QuizAttemptStatus::SUBMITTED
		);
		self::assertFalse( $ok );
	}

	public function test_count_submitted_for_user_returns_count_of_terminal_attempts(): void {
		$captured_args = [];

		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->andReturnUsing(
				function ( string $sql, ...$args ) use ( &$captured_args ): string {
					$captured_args = $args;
					return $sql;
				}
			);
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '2' );

		$count = $this->repo->count_submitted_for_user( 10, 5 );

		self::assertSame( 2, $count );
		self::assertSame( [ 10, 5, 'in_progress' ], $captured_args );
	}

	public function test_best_score_for_user_returns_max_or_null(): void {
		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '85.5' );

		self::assertSame( 85.5, $this->repo->best_score_for_user( 10, 5 ) );

		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( null );

		self::assertNull( $this->repo->best_score_for_user( 10, 5 ) );
	}
}
