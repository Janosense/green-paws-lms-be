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

	public function __construct( private readonly CurriculumOrder $order ) {
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
	 * @return \wpdb
	 */
	protected function wpdb() {
		return $GLOBALS['wpdb'];
	}
}
