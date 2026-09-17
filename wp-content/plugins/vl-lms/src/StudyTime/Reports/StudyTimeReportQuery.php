<?php

declare(strict_types=1);

namespace VL\LMS\StudyTime\Reports;

use VL\LMS\Database\SchemaManager;
use VL\LMS\Learn\Progression\CurriculumOrder;
use VL\LMS\Learn\Progression\CurriculumStop;

/**
 * Read model behind every `study-time` report surface of Sprint 2.
 *
 * A course figure is made of three sources, by `docs/DECISIONS.md`
 * 2026-09-15 (kind rules): the feature's own ledger (`video` + `reading`),
 * the duration already recorded on finished quiz attempts, and the duration
 * already recorded on Zoom attendance rows. Quiz and session time is read
 * where it lives and never copied into the ledger, so nothing here writes.
 *
 * Three deliberate properties of these reads:
 *
 * - **No progress-reset epoch.** Study time is history and survives the
 *   reset, so the attempts read carries none of the `COUNTING_PREDICATE`
 *   machinery that gate-feeding reads in `QuizAttemptRepository` use. An
 *   attempt from before a learner's reset still counts.
 * - **Topics fold into their lesson in SQL.** A `CurriculumStop` carries no
 *   parent id, so the fold uses `post_parent`, which is the documented
 *   hierarchy (`docs/DATA-MODEL.md` — `vl_lesson ─< vl_topic`).
 * - **Order and titles are read, never re-derived** — `CurriculumOrder`
 *   is the single source of the curriculum walk (root `CLAUDE.md` domain
 *   invariant 8).
 *
 * Built like `Admin\Dashboard\CourseStatsQuery`: not `final`, because unit
 * tests subclass the `$wpdb` seam; table names only from `SchemaManager`;
 * plain arrays out, with absent keys meaning zero.
 *
 * @author Tymofii Synianskyi
 */
class StudyTimeReportQuery {

	/**
	 * Quiz attempts that carry a duration. `in_progress` has not finished
	 * and `abandoned` (written by the progress reset) is never scored — both
	 * leave `time_taken_seconds` NULL.
	 */
	private const array COUNTED_ATTEMPT_STATUSES = [ 'submitted', 'expired' ];

	/**
	 * The enrollment statuses a learner's own report covers — a run in
	 * progress and a finished one. Spelled here rather than imported from
	 * `core`'s `EnrollmentStatus`, the way the attempt statuses above are:
	 * `docs/DATA-MODEL.md` is what this feature is allowed to read, and the
	 * values are its column contract.
	 */
	private const array OWN_REPORT_STATUSES = [ 'active', 'completed' ];

	public function __construct( private readonly CurriculumOrder $order ) {
	}

	/**
	 * One learner's totals across every course they are enrolled in.
	 *
	 * Four statements however many courses: the learner's course ids, then
	 * one grouped read per source filtered to that learner and those ids.
	 * A course with an enrollment and no rows comes back with zeros rather
	 * than missing, so a caller can map by course without guessing.
	 *
	 * @return array<int, array{total: int, video: int, reading: int, quiz: int, session: int}>
	 */
	public function for_user( int $user_id ): array {
		$course_ids = $this->enrolled_course_ids( $user_id );
		if ( [] === $course_ids ) {
			return [];
		}

		$ledger  = $this->ledger_by_course_and_kind_for_user( $user_id, $course_ids );
		$quiz    = $this->quiz_seconds_by_course_for_user( $user_id, $course_ids );
		$session = $this->session_seconds_by_course_for_user( $user_id, $course_ids );

		$out = [];
		foreach ( $course_ids as $course_id ) {
			$video          = $ledger[ $course_id ]['video'] ?? 0;
			$reading        = $ledger[ $course_id ]['reading'] ?? 0;
			$quiz_seconds   = $quiz[ $course_id ] ?? 0;
			$session_second = $session[ $course_id ] ?? 0;

			$out[ $course_id ] = [
				'total'   => $video + $reading + $quiz_seconds + $session_second,
				'video'   => $video,
				'reading' => $reading,
				'quiz'    => $quiz_seconds,
				'session' => $session_second,
			];
		}
		return $out;
	}

	/**
	 * The courses the learner is currently enrolled in, running or finished.
	 *
	 * `vl_enrollments` is `core`'s table, read here and never written — the
	 * one the area file names for this feature alongside `vl_quiz_attempts`
	 * and `vl_session_attendance`.
	 *
	 * @return list<int>
	 */
	private function enrolled_course_ids( int $user_id ): array {
		$wpdb     = $this->wpdb();
		$table    = SchemaManager::enrollments_table();
		$statuses = self::OWN_REPORT_STATUSES;

		$status_placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
		$args                = [ $user_id, ...$statuses ];

		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- One array of binds for a run whose length is fixed by OWN_REPORT_STATUSES.
		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from SchemaManager; the placeholder run is built from a counted array.
			"SELECT DISTINCT course_id FROM {$table} WHERE user_id = %d AND status IN ({$status_placeholders})",
			$args
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_col( $sql );

		$out = [];
		if ( is_array( $rows ) ) {
			foreach ( $rows as $value ) {
				$course_id = (int) $value;
				if ( $course_id > 0 ) {
					$out[] = $course_id;
				}
			}
		}
		return $out;
	}

	/**
	 * @param list<int> $course_ids
	 * @return array<int, array<string, int>> `course => kind => seconds`
	 */
	private function ledger_by_course_and_kind_for_user( int $user_id, array $course_ids ): array {
		$wpdb         = $this->wpdb();
		$table        = SchemaManager::study_time_table();
		$placeholders = implode( ', ', array_fill( 0, count( $course_ids ), '%d' ) );
		$args         = [ $user_id, ...$course_ids ];

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- Table name from SchemaManager; the placeholder run is built from a counted array.
		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- One array of binds: the learner, then the id run.
		$sql  = $wpdb->prepare(
			"SELECT course_id, kind, SUM(active_seconds) AS seconds
				FROM {$table}
				WHERE user_id = %d AND course_id IN ({$placeholders})
				GROUP BY course_id, kind",
			$args
		);
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared

		$out = [];
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$course_id = (int) ( $row['course_id'] ?? 0 );
				$kind      = (string) ( $row['kind'] ?? '' );
				if ( $course_id > 0 && '' !== $kind ) {
					$out[ $course_id ][ $kind ] = (int) ( $row['seconds'] ?? 0 );
				}
			}
		}
		return $out;
	}

	/**
	 * @param list<int> $course_ids
	 * @return array<int, int> `course => seconds`
	 */
	private function quiz_seconds_by_course_for_user( int $user_id, array $course_ids ): array {
		$wpdb     = $this->wpdb();
		$table    = SchemaManager::quiz_attempts_table();
		$statuses = self::COUNTED_ATTEMPT_STATUSES;

		$id_placeholders     = implode( ', ', array_fill( 0, count( $course_ids ), '%d' ) );
		$status_placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
		$args                = [ $user_id, ...$course_ids, ...$statuses ];

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- Table name from SchemaManager; both placeholder runs are built from counted arrays.
		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- One array of binds for two counted runs.
		$sql  = $wpdb->prepare(
			"SELECT course_id, COALESCE(SUM(time_taken_seconds), 0) AS seconds
				FROM {$table}
				WHERE user_id = %d AND course_id IN ({$id_placeholders}) AND status IN ({$status_placeholders})
				GROUP BY course_id",
			$args
		);
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared

		return $this->fold_id_seconds( $rows, 'course_id' );
	}

	/**
	 * @param list<int> $course_ids
	 * @return array<int, int> `course => seconds`
	 */
	private function session_seconds_by_course_for_user( int $user_id, array $course_ids ): array {
		$wpdb         = $this->wpdb();
		$table        = SchemaManager::session_attendance_table();
		$posts        = $wpdb->posts;
		$placeholders = implode( ', ', array_fill( 0, count( $course_ids ), '%d' ) );
		$args         = [ $user_id, ...$course_ids ];

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- Table names from SchemaManager and $wpdb; the placeholder run is built from a counted array.
		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- One array of binds: the learner, then the id run.
		$sql  = $wpdb->prepare(
			"SELECT p.post_parent AS course_id, COALESCE(SUM(a.duration_seconds), 0) AS seconds
				FROM {$table} a
				INNER JOIN {$posts} p ON p.ID = a.session_id
				WHERE a.user_id = %d AND p.post_type = 'vl_session' AND p.post_parent IN ({$placeholders})
				GROUP BY p.post_parent",
			$args
		);
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared

		return $this->fold_id_seconds( $rows, 'course_id' );
	}

	/**
	 * @param mixed $rows
	 * @return array<int, int>
	 */
	private function fold_id_seconds( $rows, string $key ): array {
		$out = [];
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$id = (int) ( $row[ $key ] ?? 0 );
				if ( $id > 0 ) {
					$out[ $id ] = ( $out[ $id ] ?? 0 ) + (int) ( $row['seconds'] ?? 0 );
				}
			}
		}
		return $out;
	}

	/**
	 * One learner's time in one course.
	 *
	 * `lessons` lists only the lessons the learner actually spent time on,
	 * in curriculum order: this answers "where did the time go", and a
	 * course-length table of zeros would bury it. Seconds whose entity is
	 * gone, or whose lesson is no longer in the curriculum, stay in `total`
	 * and in the kind figures but appear in no lesson row — the arithmetic
	 * never loses a second.
	 *
	 * @return array{
	 *     total: int, video: int, reading: int, quiz: int, session: int,
	 *     lessons: list<array{lesson_id: int, title: string, video: int, reading: int, total: int}>
	 * }
	 */
	public function for_enrollment( int $user_id, int $course_id ): array {
		$by_lesson = $this->ledger_by_lesson_and_kind( $user_id, $course_id );

		$video   = 0;
		$reading = 0;
		foreach ( $by_lesson as $kinds ) {
			$video   += $kinds['video'] ?? 0;
			$reading += $kinds['reading'] ?? 0;
		}

		$quiz    = $this->quiz_seconds_for_user_in_course( $user_id, $course_id );
		$session = $this->session_seconds_for_user_in_course( $user_id, $course_id );

		$lessons = [];
		foreach ( $this->lesson_stops( $course_id ) as $lesson_id => $title ) {
			$kinds = $by_lesson[ $lesson_id ] ?? null;
			if ( null === $kinds ) {
				continue;
			}
			$lesson_video   = $kinds['video'] ?? 0;
			$lesson_reading = $kinds['reading'] ?? 0;
			$lesson_total   = $lesson_video + $lesson_reading;
			if ( 0 === $lesson_total ) {
				continue;
			}
			$lessons[] = [
				'lesson_id' => $lesson_id,
				'title'     => $title,
				'video'     => $lesson_video,
				'reading'   => $lesson_reading,
				'total'     => $lesson_total,
			];
		}

		return [
			'total'   => $video + $reading + $quiz + $session,
			'video'   => $video,
			'reading' => $reading,
			'quiz'    => $quiz,
			'session' => $session,
			'lessons' => $lessons,
		];
	}

	/**
	 * The learner's ledger seconds in one course, folded onto lessons.
	 *
	 * @return array<int, array<string, int>> `lesson_id => kind => seconds`;
	 *                                        lesson id 0 collects rows whose
	 *                                        entity post is gone.
	 */
	private function ledger_by_lesson_and_kind( int $user_id, int $course_id ): array {
		$wpdb  = $this->wpdb();
		$table = SchemaManager::study_time_table();
		$posts = $wpdb->posts;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- Table names come from SchemaManager and $wpdb; every value is a placeholder.
		$sql  = $wpdb->prepare(
			"SELECT CASE WHEN s.entity_type = 'topic' THEN p.post_parent ELSE s.entity_id END AS lesson_id,
					s.kind AS kind,
					SUM(s.active_seconds) AS seconds
				FROM {$table} s
				LEFT JOIN {$posts} p ON p.ID = s.entity_id
				WHERE s.user_id = %d AND s.course_id = %d
				GROUP BY lesson_id, s.kind",
			$user_id,
			$course_id
		);
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared

		$out = [];
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$lesson_id                  = (int) ( $row['lesson_id'] ?? 0 );
				$kind                       = (string) ( $row['kind'] ?? '' );
				$out[ $lesson_id ][ $kind ] = ( $out[ $lesson_id ][ $kind ] ?? 0 ) + (int) ( $row['seconds'] ?? 0 );
			}
		}
		return $out;
	}

	/**
	 * `lesson_id => title`, in the order the curriculum lists them.
	 *
	 * @return array<int, string>
	 */
	private function lesson_stops( int $course_id ): array {
		$out = [];
		foreach ( $this->order->for_course( $course_id ) as $stop ) {
			if ( CurriculumStop::KIND_LESSON === $stop->kind ) {
				$out[ $stop->id ] = $stop->title;
			}
		}
		return $out;
	}

	/**
	 * Seconds already recorded on the learner's finished attempts in this
	 * course. `vl_quiz_attempts.course_id` is stamped when the attempt
	 * starts, so the quizzes of a course need no post-tree walk.
	 */
	private function quiz_seconds_for_user_in_course( int $user_id, int $course_id ): int {
		$wpdb     = $this->wpdb();
		$table    = SchemaManager::quiz_attempts_table();
		$statuses = self::COUNTED_ATTEMPT_STATUSES;

		$status_placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

		// The binds run user id, course id, then the status list — the same
		// order as the placeholders in the statement below.
		$args = [ $user_id, $course_id, ...$statuses ];

		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- One array of binds for a placeholder run whose length is fixed by COUNTED_ATTEMPT_STATUSES.
		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from SchemaManager; the placeholder run is built from a counted array.
			"SELECT COALESCE(SUM(time_taken_seconds), 0) FROM {$table} WHERE user_id = %d AND course_id = %d AND status IN ({$status_placeholders})",
			$args
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Seconds already recorded on the learner's attendance rows for this
	 * course's sessions.
	 *
	 * A session hangs directly off the course (`docs/DATA-MODEL.md`), so one
	 * join resolves it. Three properties of that table are deliberate here:
	 * a learner can hold several rows for one session (a rejoin under a new
	 * Zoom participant UUID) and summing them is what "time attended" means;
	 * an open row's `duration_seconds` is NULL and contributes nothing until
	 * Zoom reports the leave; and the join filters `post_type` and
	 * `post_parent` but not `post_status`, because attendance is a
	 * historical fact that a session later moved to draft must not erase.
	 */
	private function session_seconds_for_user_in_course( int $user_id, int $course_id ): int {
		$wpdb  = $this->wpdb();
		$table = SchemaManager::session_attendance_table();
		$posts = $wpdb->posts;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- Table names come from SchemaManager and $wpdb; every value is a placeholder.
		$sql   = $wpdb->prepare(
			"SELECT COALESCE(SUM(a.duration_seconds), 0)
				FROM {$table} a
				INNER JOIN {$posts} p ON p.ID = a.session_id
				WHERE a.user_id = %d AND p.post_type = 'vl_session' AND p.post_parent = %d",
			$user_id,
			$course_id
		);
		$value = $wpdb->get_var( $sql );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared

		return (int) $value;
	}

	/**
	 * One course's figures across its learners.
	 *
	 * Averages are taken over the learners who have time, never over every
	 * enrolled learner (`docs/DECISIONS.md` 2026-09-15): a course with many
	 * never-started enrollments would otherwise read as "short". The course
	 * denominator is the learners whose combined total is above zero — the
	 * same denominator for all four kinds, so the parts still add up to the
	 * whole. A lesson has its own denominator: the learners with time on
	 * that lesson, reported beside it as `learners`.
	 *
	 * @return array{
	 *     learners_with_time: int, avg_total: int, avg_video: int, avg_reading: int,
	 *     avg_quiz: int, avg_session: int,
	 *     lessons: list<array{lesson_id: int, title: string, avg_total: int, learners: int}>
	 * }
	 */
	public function for_course( int $course_id ): array {
		$by_user  = $this->seconds_by_course_and_user( [ $course_id ] )[ $course_id ] ?? [];
		$learners = $this->learners_with_time( $by_user );
		$count    = count( $learners );

		$sums = [
			'video'   => 0,
			'reading' => 0,
			'quiz'    => 0,
			'session' => 0,
		];
		foreach ( $learners as $user_id ) {
			foreach ( array_keys( $sums ) as $source ) {
				$sums[ $source ] += $by_user[ $user_id ][ $source ] ?? 0;
			}
		}

		return [
			'learners_with_time' => $count,
			'avg_total'          => $this->average( array_sum( $sums ), $count ),
			'avg_video'          => $this->average( $sums['video'], $count ),
			'avg_reading'        => $this->average( $sums['reading'], $count ),
			'avg_quiz'           => $this->average( $sums['quiz'], $count ),
			'avg_session'        => $this->average( $sums['session'], $count ),
			'lessons'            => $this->lesson_averages( $course_id ),
		];
	}

	/**
	 * Average total seconds per course, for a whole list of courses at once.
	 *
	 * Reads exactly the same per-learner figures as {@see self::for_course()}
	 * — one batched statement per source table, never one round trip per
	 * course — so «Панель інструктора» and the analytics table can never
	 * disagree about a course. Courses nobody has studied are absent from
	 * the map; callers default with `?? 0`.
	 *
	 * @param list<int> $course_ids
	 * @return array<int, int>
	 */
	public function avg_total_by_course( array $course_ids ): array {
		$out = [];
		foreach ( $this->seconds_by_course_and_user( $course_ids ) as $course_id => $by_user ) {
			$learners = $this->learners_with_time( $by_user );
			if ( [] === $learners ) {
				continue;
			}
			$total = 0;
			foreach ( $learners as $user_id ) {
				$total += array_sum( $by_user[ $user_id ] );
			}
			$out[ $course_id ] = $this->average( $total, count( $learners ) );
		}
		return $out;
	}

	/**
	 * Every learner's seconds in the given courses, split by source.
	 *
	 * Three batched statements — ledger, attempts, attendance — merged in
	 * PHP. A `UNION ALL` over three differently shaped tables would read
	 * worse and test worse for the same round trips.
	 *
	 * @param list<int> $course_ids
	 * @return array<int, array<int, array<string, int>>> `course => user => source => seconds`
	 */
	private function seconds_by_course_and_user( array $course_ids ): array {
		$ids = array_values( array_unique( array_filter( $course_ids, static fn ( int $id ): bool => $id > 0 ) ) );
		if ( [] === $ids ) {
			return [];
		}

		$out = [];
		foreach ( $this->ledger_by_course_user_and_kind( $ids ) as $course_id => $users ) {
			foreach ( $users as $user_id => $kinds ) {
				foreach ( $kinds as $kind => $seconds ) {
					$out[ $course_id ][ $user_id ][ $kind ] = $seconds;
				}
			}
		}
		foreach ( $this->quiz_seconds_by_course_and_user( $ids ) as $course_id => $users ) {
			foreach ( $users as $user_id => $seconds ) {
				$out[ $course_id ][ $user_id ]['quiz'] = $seconds;
			}
		}
		foreach ( $this->session_seconds_by_course_and_user( $ids ) as $course_id => $users ) {
			foreach ( $users as $user_id => $seconds ) {
				$out[ $course_id ][ $user_id ]['session'] = $seconds;
			}
		}
		return $out;
	}

	/**
	 * The learners of one course whose combined total is above zero.
	 *
	 * @param array<int, array<string, int>> $by_user
	 * @return list<int>
	 */
	private function learners_with_time( array $by_user ): array {
		$out = [];
		foreach ( $by_user as $user_id => $sources ) {
			if ( array_sum( $sources ) > 0 ) {
				$out[] = (int) $user_id;
			}
		}
		return $out;
	}

	/**
	 * Whole seconds, and zero rather than a division when nobody qualifies.
	 */
	private function average( int $total, int $learners ): int {
		if ( $learners <= 0 ) {
			return 0;
		}
		return (int) round( $total / $learners );
	}

	/**
	 * Per-lesson averages in curriculum order, each over the learners with
	 * time on that lesson. Lessons nobody studied are left out, like the
	 * per-enrollment table.
	 *
	 * @return list<array{lesson_id: int, title: string, avg_total: int, learners: int}>
	 */
	private function lesson_averages( int $course_id ): array {
		$by_lesson = $this->ledger_by_lesson_and_user( $course_id );

		$out = [];
		foreach ( $this->lesson_stops( $course_id ) as $lesson_id => $title ) {
			$users = array_filter( $by_lesson[ $lesson_id ] ?? [], static fn ( int $seconds ): bool => $seconds > 0 );
			if ( [] === $users ) {
				continue;
			}
			$out[] = [
				'lesson_id' => $lesson_id,
				'title'     => $title,
				'avg_total' => $this->average( (int) array_sum( $users ), count( $users ) ),
				'learners'  => count( $users ),
			];
		}
		return $out;
	}

	/**
	 * @param list<int> $course_ids
	 * @return array<int, array<int, array<string, int>>> `course => user => kind => seconds`
	 */
	private function ledger_by_course_user_and_kind( array $course_ids ): array {
		$wpdb         = $this->wpdb();
		$table        = SchemaManager::study_time_table();
		$placeholders = implode( ', ', array_fill( 0, count( $course_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- Table name from SchemaManager; the placeholder run is built from a counted array.
		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- One array of binds for a run whose length is the batch size.
		$sql  = $wpdb->prepare(
			"SELECT course_id, user_id, kind, SUM(active_seconds) AS seconds
				FROM {$table}
				WHERE course_id IN ({$placeholders})
				GROUP BY course_id, user_id, kind",
			$course_ids
		);
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared

		$out = [];
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$course_id = (int) ( $row['course_id'] ?? 0 );
				$user_id   = (int) ( $row['user_id'] ?? 0 );
				$kind      = (string) ( $row['kind'] ?? '' );
				if ( $course_id > 0 && $user_id > 0 && '' !== $kind ) {
					$out[ $course_id ][ $user_id ][ $kind ] = (int) ( $row['seconds'] ?? 0 );
				}
			}
		}
		return $out;
	}

	/**
	 * @param list<int> $course_ids
	 * @return array<int, array<int, int>> `course => user => seconds`
	 */
	private function quiz_seconds_by_course_and_user( array $course_ids ): array {
		$wpdb     = $this->wpdb();
		$table    = SchemaManager::quiz_attempts_table();
		$statuses = self::COUNTED_ATTEMPT_STATUSES;

		$id_placeholders     = implode( ', ', array_fill( 0, count( $course_ids ), '%d' ) );
		$status_placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

		// The course ids bind first, then the status list — placeholder order.
		$args = [ ...$course_ids, ...$statuses ];

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- Table name from SchemaManager; both placeholder runs are built from counted arrays.
		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- One array of binds for two counted runs.
		$sql  = $wpdb->prepare(
			"SELECT course_id, user_id, COALESCE(SUM(time_taken_seconds), 0) AS seconds
				FROM {$table}
				WHERE course_id IN ({$id_placeholders}) AND status IN ({$status_placeholders})
				GROUP BY course_id, user_id",
			$args
		);
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared

		return $this->fold_course_user_seconds( $rows );
	}

	/**
	 * @param list<int> $course_ids
	 * @return array<int, array<int, int>> `course => user => seconds`
	 */
	private function session_seconds_by_course_and_user( array $course_ids ): array {
		$wpdb         = $this->wpdb();
		$table        = SchemaManager::session_attendance_table();
		$posts        = $wpdb->posts;
		$placeholders = implode( ', ', array_fill( 0, count( $course_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- Table names come from SchemaManager and $wpdb; the placeholder run is built from a counted array.
		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- One array of binds for a run whose length is the batch size.
		$sql  = $wpdb->prepare(
			"SELECT p.post_parent AS course_id, a.user_id AS user_id, COALESCE(SUM(a.duration_seconds), 0) AS seconds
				FROM {$table} a
				INNER JOIN {$posts} p ON p.ID = a.session_id
				WHERE p.post_type = 'vl_session' AND p.post_parent IN ({$placeholders})
				GROUP BY p.post_parent, a.user_id",
			$course_ids
		);
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared

		return $this->fold_course_user_seconds( $rows );
	}

	/**
	 * The learners' ledger seconds on each lesson of one course.
	 *
	 * @return array<int, array<int, int>> `lesson_id => user => seconds`
	 */
	private function ledger_by_lesson_and_user( int $course_id ): array {
		$wpdb  = $this->wpdb();
		$table = SchemaManager::study_time_table();
		$posts = $wpdb->posts;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- Table names come from SchemaManager and $wpdb; every value is a placeholder.
		$sql  = $wpdb->prepare(
			"SELECT CASE WHEN s.entity_type = 'topic' THEN p.post_parent ELSE s.entity_id END AS lesson_id,
					s.user_id AS user_id,
					SUM(s.active_seconds) AS seconds
				FROM {$table} s
				LEFT JOIN {$posts} p ON p.ID = s.entity_id
				WHERE s.course_id = %d
				GROUP BY lesson_id, s.user_id",
			$course_id
		);
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared

		$out = [];
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$lesson_id = (int) ( $row['lesson_id'] ?? 0 );
				$user_id   = (int) ( $row['user_id'] ?? 0 );
				if ( $lesson_id > 0 && $user_id > 0 ) {
					$out[ $lesson_id ][ $user_id ] = ( $out[ $lesson_id ][ $user_id ] ?? 0 ) + (int) ( $row['seconds'] ?? 0 );
				}
			}
		}
		return $out;
	}

	/**
	 * @param mixed $rows
	 * @return array<int, array<int, int>>
	 */
	private function fold_course_user_seconds( $rows ): array {
		$out = [];
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$course_id = (int) ( $row['course_id'] ?? 0 );
				$user_id   = (int) ( $row['user_id'] ?? 0 );
				if ( $course_id > 0 && $user_id > 0 ) {
					$out[ $course_id ][ $user_id ] = ( $out[ $course_id ][ $user_id ] ?? 0 ) + (int) ( $row['seconds'] ?? 0 );
				}
			}
		}
		return $out;
	}

	/**
	 * @return \wpdb
	 */
	protected function wpdb() {
		return $GLOBALS['wpdb'];
	}
}
