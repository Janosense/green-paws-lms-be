<?php

declare(strict_types=1);

namespace VL\LMS\Api\Transformers;

use VL\LMS\Domain\Quiz\QuizAttempt;
use VL\LMS\Repositories\QuizAttemptRepository;

/**
 * Single source of truth for the wire shape of a quiz attempt.
 *
 * Phase 9.5 added three additive fields — `quiz_title`,
 * `attempts_remaining`, `best_score` — that the legacy controller
 * envelope did not expose. To keep `QuizAttemptsController` free of
 * meta lookups and per-user aggregates, the envelope is built here.
 *
 * Indirected `title()` / `meta_int()` / `now()` seams exist so unit
 * tests can subclass without round-tripping through `get_the_title`
 * and `get_post_meta`. Not declared `final` for that reason.
 *
 * @author Tymofii Synianskyi
 */
class QuizAttemptStateTransformer {

	public function __construct(
		private readonly QuizAttemptRepository $repo
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function transform_attempt( QuizAttempt $attempt ): array {
		$time_remaining = null;
		if ( $attempt->time_limit_seconds > 0 ) {
			$elapsed        = $this->now() - $attempt->started_at->getTimestamp();
			$remaining      = $attempt->time_limit_seconds - $elapsed;
			$time_remaining = $remaining > 0 ? $remaining : 0;
		}

		$max_attempts       = $this->meta_int( $attempt->quiz_id, '_vl_quiz_max_attempts' );
		$attempts_remaining = null;
		if ( $max_attempts > 0 ) {
			$submitted          = $this->repo->count_submitted_for_user( $attempt->quiz_id, $attempt->user_id );
			$attempts_remaining = max( 0, $max_attempts - $submitted );
		}

		return [
			'id'                     => $attempt->id,
			'quiz_id'                => $attempt->quiz_id,
			'quiz_title'             => $this->title( $attempt->quiz_id ),
			'course_id'              => $attempt->course_id,
			'course_slug'            => $this->course_slug( $attempt->course_id ),
			'status'                 => $attempt->status->value,
			'started_at'             => $attempt->started_at->format( \DateTimeInterface::ATOM ),
			'submitted_at'           => null === $attempt->submitted_at
				? null
				: $attempt->submitted_at->format( \DateTimeInterface::ATOM ),
			'time_limit_seconds'     => $attempt->time_limit_seconds,
			'time_remaining_seconds' => $time_remaining,
			'time_taken_seconds'     => $attempt->time_taken_seconds,
			'max_score'              => $attempt->max_score,
			'score'                  => $attempt->score,
			'passed'                 => $attempt->passed,
			'passing_threshold'      => $attempt->passing_threshold,
			'question_order'         => $attempt->question_order,
			'attempts_remaining'     => $attempts_remaining,
			'best_score'             => $this->repo->best_score_for_user( $attempt->quiz_id, $attempt->user_id ),
		];
	}

	protected function title( int $quiz_id ): string {
		$value = get_the_title( $quiz_id );
		return is_string( $value ) ? $value : '';
	}

	/**
	 * Resolves the attempt's course `post_name`, so the curriculum-rail
	 * frontend can hydrate the surrounding course from a deep-linked quiz
	 * page. Empty string when the course is missing (defensive).
	 */
	protected function course_slug( int $course_id ): string {
		$value = get_post_field( 'post_name', $course_id );
		return is_string( $value ) ? $value : '';
	}

	protected function meta_int( int $post_id, string $key ): int {
		$value = get_post_meta( $post_id, $key, true );
		return is_numeric( $value ) ? (int) $value : 0;
	}

	protected function now(): int {
		return time();
	}
}
