<?php

declare(strict_types=1);

namespace VL\LMS\Api\Transformers;

use VL\LMS\Domain\Quiz\QuizAttempt;
use VL\LMS\Domain\Quiz\QuizAttemptStatus;
use VL\LMS\Support\PlainText;

/**
 * Wire shape for `GET /quizzes/{slug}/attempts` — the learner's own
 * attempt log for one quiz.
 *
 * Deliberately *not* built on {@see QuizAttemptStateTransformer}. That
 * transformer answers "what does the player need to render this attempt",
 * so it carries `question_order` and a live `time_remaining_seconds`, and
 * it runs two per-user aggregate queries (`count_submitted_for_user`,
 * `best_score_for_user`) every time it is called. Mapping it over a list
 * of N attempts would ship N copies of the same two quiz-level numbers
 * and cost 2N queries to do it. Here the aggregates are hoisted to the
 * envelope and derived from the already-loaded rows, so the whole
 * response costs the one `list_for_user_in_quiz` round trip the service
 * already made.
 *
 * `attempts_remaining` and `best_score` keep the definitions the
 * attempt-state envelope uses, so the two endpoints never disagree:
 * remaining counts every non-`in_progress` row against
 * `_vl_quiz_max_attempts`, and best-score considers `submitted` rows only
 * (an `expired` attempt is scored, but it is not a completed sitting).
 *
 * Indirected `title()` / `course_slug()` / `meta_int()` seams exist so
 * unit tests can subclass without round-tripping through `get_the_title`
 * and `get_post_meta`. Not declared `final` for that reason.
 *
 * @author Tymofii Synianskyi
 */
class QuizAttemptHistoryTransformer {

	/**
	 * @param int             $quiz_id  Quiz the history belongs to.
	 * @param list<QuizAttempt> $attempts Oldest-first, as returned by
	 *                                    {@see \VL\LMS\Quiz\QuizAttemptService::history()}.
	 * @return array<string, mixed>
	 */
	public function transform( int $quiz_id, array $attempts ): array {
		$max_attempts = $this->meta_int( $quiz_id, '_vl_quiz_max_attempts' );

		$graded            = 0;
		$best_score        = null;
		$passed_on_attempt = null;
		$rows              = [];

		foreach ( $attempts as $index => $attempt ) {
			$number = $index + 1;

			if ( QuizAttemptStatus::IN_PROGRESS !== $attempt->status ) {
				++$graded;
			}

			if ( QuizAttemptStatus::SUBMITTED === $attempt->status ) {
				$pct = $this->score_percent( $attempt );
				if ( null !== $pct && ( null === $best_score || $pct > $best_score ) ) {
					$best_score = $pct;
				}
			}

			if ( true === $attempt->passed && null === $passed_on_attempt ) {
				$passed_on_attempt = $number;
			}

			$rows[] = $this->transform_row( $attempt, $number );
		}

		$course_id = $this->course_id_of( $attempts );

		return [
			'quiz_id'            => $quiz_id,
			'quiz_title'         => $this->title( $quiz_id ),
			'course_id'          => $course_id,
			'course_slug'        => $this->course_slug( $course_id ),
			'max_attempts'       => $max_attempts,
			'attempts_remaining' => $max_attempts > 0 ? max( 0, $max_attempts - $graded ) : null,
			'total_attempts'     => count( $attempts ),
			'graded_attempts'    => $graded,
			'best_score'         => $best_score,
			'passed'             => null !== $passed_on_attempt,
			'passed_on_attempt'  => $passed_on_attempt,
			'attempts'           => $rows,
		];
	}

	/**
	 * One history row. Leaner than the player envelope: no
	 * `question_order`, no `time_remaining_seconds` (both are live-attempt
	 * concerns), plus a derived `score_percent` so the client can compare
	 * against `passing_threshold` — which is a percentage — without
	 * redoing the division and rounding on every row.
	 *
	 * @return array<string, mixed>
	 */
	protected function transform_row( QuizAttempt $attempt, int $number ): array {
		return [
			'id'                 => $attempt->id,
			'attempt_number'     => $number,
			'status'             => $attempt->status->value,
			'started_at'         => $attempt->started_at->format( \DateTimeInterface::ATOM ),
			'submitted_at'       => null === $attempt->submitted_at
				? null
				: $attempt->submitted_at->format( \DateTimeInterface::ATOM ),
			'score'              => $attempt->score,
			'max_score'          => $attempt->max_score,
			'score_percent'      => $this->score_percent( $attempt ),
			'passed'             => $attempt->passed,
			'passing_threshold'  => $attempt->passing_threshold,
			'time_taken_seconds' => $attempt->time_taken_seconds,
		];
	}

	/**
	 * Percentage for one attempt, or `null` when it was never scored or
	 * the snapshot carries no points (a quiz with no questions).
	 *
	 * Mirrors the SQL in
	 * {@see \VL\LMS\Repositories\QuizAttemptRepository::best_score_for_user()}
	 * — `ROUND(score / max_score * 100, 2)` — so a value computed here
	 * compares equal to one computed there.
	 */
	protected function score_percent( QuizAttempt $attempt ): ?float {
		if ( null === $attempt->score || $attempt->max_score <= 0 ) {
			return null;
		}
		return round( $attempt->score / $attempt->max_score * 100, 2 );
	}

	/**
	 * Course the attempts belong to, read off the snapshotted rows rather
	 * than re-resolving the `post_parent` chain. Zero when the learner has
	 * no attempts yet — the client has the course context already in that
	 * case, since it got here from the course.
	 *
	 * @param list<QuizAttempt> $attempts
	 */
	protected function course_id_of( array $attempts ): int {
		return isset( $attempts[0] ) ? $attempts[0]->course_id : 0;
	}

	protected function title( int $quiz_id ): string {
		$value = get_the_title( $quiz_id );
		return is_string( $value ) ? PlainText::from_html( $value ) : '';
	}

	protected function course_slug( int $course_id ): string {
		if ( $course_id <= 0 ) {
			return '';
		}
		$value = get_post_field( 'post_name', $course_id );
		return is_string( $value ) ? $value : '';
	}

	protected function meta_int( int $post_id, string $key ): int {
		$value = get_post_meta( $post_id, $key, true );
		return is_numeric( $value ) ? (int) $value : 0;
	}
}
