<?php

declare(strict_types=1);

namespace VL\LMS\Quiz;

use VL\LMS\Domain\Quiz\QuestionType;
use VL\LMS\Domain\Quiz\QuizAnswer;
use VL\LMS\Domain\Quiz\QuizAttempt;
use VL\LMS\Domain\Quiz\QuizAttemptStatus;
use VL\LMS\Domain\Quiz\ShowCorrectAnswersPolicy;
use VL\LMS\Quiz\Access\QuizAccessGate;
use VL\LMS\Quiz\Scoring\QuizScoringEngine;
use VL\LMS\Repositories\QuizAnswerRepository;
use VL\LMS\Repositories\QuizAttemptRepository;
use VL\LMS\Services\Progress\CompletionPropagator;
use VL\LMS\Support\Logger;
use WP_Post;
use WP_Query;

/**
 * Orchestrator for quiz attempts.
 *
 * Owns the lifecycle: `start` → `save_answer` (n times) → `submit`. Plus
 * `fetch_state` for the resume path. The repository and scoring engine
 * are dumb — every business decision (idempotency, time-limit
 * enforcement, fan-up trigger) lives here.
 *
 * Snapshot semantics from Phase 6.0 are honored end-to-end: once an
 * attempt row exists, all quiz config (`time_limit_seconds`,
 * `passing_threshold`, `max_score`, `question_order`) is read from the
 * attempt — never from the source CPT. Instructor edits to the quiz
 * after start never alter an in-flight or finalized attempt.
 *
 * Three protected seams isolate WordPress integration so unit tests can
 * subclass cleanly:
 *
 * - {@see self::list_quiz_questions()} — the `WP_Query` that fetches
 *   the published `vl_quiz_question` children of a quiz.
 * - {@see self::find_quiz_by_id()} — the `get_post()` lookup.
 * - {@see self::current_time_utc()} — the wall-clock seam (the
 *   Phase-5 Patchwork `time()` redefinition is already enabled, but
 *   some date arithmetic paths benefit from a callable seam).
 *
 * @author Tymofii Synianskyi
 */
class QuizAttemptService {

	private const int TEXT_ANSWER_MAX_LEN = 5000;

	public function __construct(
		private readonly QuizAccessGate $gate,
		private readonly QuizAttemptRepository $attempts,
		private readonly QuizAnswerRepository $answers,
		private readonly QuizScoringEngine $scoring,
		private readonly QuestionDeliveryTransformer $delivery,
		private readonly QuizCourseResolver $resolver,
		private readonly CompletionPropagator $propagator,
		private readonly Logger $logger
	) {
	}

	/**
	 * Start a new attempt or return the existing in-progress one.
	 *
	 * @throws QuizAttemptException With the gate's reason code on denial.
	 */
	public function start( int $user_id, WP_Post $quiz ): AttemptStateResult {
		$quiz_id = (int) $quiz->ID;

		$existing = $this->attempts->find_active_for_user_in_quiz( $user_id, $quiz_id );
		if ( null !== $existing ) {
			return $this->build_state( $existing, $quiz_id, false );
		}

		$decision = $this->gate->evaluate_for_start( $user_id, $quiz_id, $quiz );
		if ( ! $decision->allowed ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Domain exception with controlled string code, never rendered as HTML.
			throw new QuizAttemptException( (string) $decision->reason, '', null, $decision->lock );
		}

		$course_id = $this->resolver->find_course_id_for_quiz( $quiz_id );
		if ( null === $course_id ) {
			throw new QuizAttemptException( 'quiz_not_in_course' );
		}

		$time_limit     = $this->meta_int( $quiz_id, '_vl_quiz_time_limit_seconds' );
		$threshold      = $this->meta_int( $quiz_id, '_vl_quiz_passing_threshold' );
		$shuffle        = '1' === (string) $this->meta_raw( $quiz_id, '_vl_quiz_shuffle_questions' );
		$questions      = $this->list_quiz_questions( $quiz_id );
		$max_score      = $this->compute_max_score( $questions );
		$question_order = array_map( static fn ( WP_Post $q ): int => (int) $q->ID, $questions );
		if ( $shuffle ) {
			shuffle( $question_order );
		}

		$now = $this->current_time_utc();

		$attempt = new QuizAttempt(
			0,
			$user_id,
			$quiz_id,
			$course_id,
			QuizAttemptStatus::IN_PROGRESS,
			$now,
			null,
			$time_limit,
			null,
			null,
			$max_score,
			null,
			$threshold,
			$question_order,
			$now,
			$now
		);

		$id = $this->attempts->insert( $attempt );

		$inserted = $this->attempts->find( $id );
		if ( null === $inserted ) {
			throw new QuizAttemptException( 'internal_error', 'Failed to read back inserted attempt.' );
		}

		return $this->build_state( $inserted, $quiz_id, true );
	}

	/**
	 * Load an existing attempt's state.
	 *
	 * @throws QuizAttemptException
	 */
	public function fetch_state( int $user_id, int $attempt_id ): AttemptStateResult {
		$attempt = $this->attempts->find( $attempt_id );
		if ( null === $attempt ) {
			throw new QuizAttemptException( 'attempt_not_found' );
		}

		$decision = $this->gate->evaluate_for_attempt_action( $user_id, $attempt );
		if ( ! $decision->allowed ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Domain exception with controlled string code, never rendered as HTML.
			throw new QuizAttemptException( (string) $decision->reason );
		}

		return $this->build_state( $attempt, $attempt->quiz_id, false );
	}

	/**
	 * Every attempt the caller has made at one quiz, oldest first.
	 *
	 * Read-only and gated by {@see QuizAccessGate::evaluate_for_read()} —
	 * the max-attempts ceiling is deliberately not applied, because a
	 * learner who has spent every attempt is exactly who wants to look at
	 * the record of them.
	 *
	 * The repository orders newest-first (it exists to answer "what was the
	 * latest attempt"); history reverses that, because attempt *numbering*
	 * runs forward and the panel reads as "спроба 1, 2, 3…".
	 *
	 * @return list<QuizAttempt>
	 *
	 * @throws QuizAttemptException With the gate's reason code on denial.
	 */
	public function history( int $user_id, WP_Post $quiz ): array {
		$quiz_id = (int) $quiz->ID;

		$decision = $this->gate->evaluate_for_read( $user_id, $quiz_id, $quiz );
		if ( ! $decision->allowed ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Domain exception with controlled string code, never rendered as HTML.
			throw new QuizAttemptException( (string) $decision->reason );
		}

		$attempts = $this->attempts->list_for_user_in_quiz( $user_id, $quiz_id );

		// Sort ascending on the start instant rather than array_reverse()ing
		// the repository order: `started_at` has second granularity, so two
		// rows can tie, and only `id` breaks that tie deterministically.
		usort(
			$attempts,
			static function ( QuizAttempt $a, QuizAttempt $b ): int {
				$by_time = $a->started_at <=> $b->started_at;
				return 0 !== $by_time ? $by_time : ( $a->id <=> $b->id );
			}
		);

		return $attempts;
	}

	/**
	 * Upsert an answer or auto-finalize on time-limit overrun.
	 *
	 * @param array<string, mixed> $raw_answer_data
	 *
	 * @throws QuizAttemptException
	 */
	public function save_answer(
		int $user_id,
		int $attempt_id,
		int $question_id,
		array $raw_answer_data
	): SaveAnswerResult {
		$attempt = $this->attempts->find( $attempt_id );
		if ( null === $attempt ) {
			throw new QuizAttemptException( 'attempt_not_found' );
		}

		$decision = $this->gate->evaluate_for_attempt_action( $user_id, $attempt );
		if ( ! $decision->allowed ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Domain exception with controlled string code, never rendered as HTML.
			throw new QuizAttemptException( (string) $decision->reason );
		}

		$now = $this->current_time_utc();

		// Time-limit auto-expire — the server is the source of truth (C3).
		if ( $attempt->time_limit_seconds > 0 && QuizAttemptStatus::IN_PROGRESS === $attempt->status ) {
			$elapsed = $now->getTimestamp() - $attempt->started_at->getTimestamp();
			if ( $elapsed > $attempt->time_limit_seconds ) {
				$finalized = $this->finalize_internal( $attempt, $now, true );
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Domain exception carrying a VO; controller maps to JSON.
				throw new QuizAttemptException( 'attempt_expired', '', $finalized );
			}
		}

		if ( QuizAttemptStatus::IN_PROGRESS !== $attempt->status ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Domain exception carrying a VO; controller maps to JSON.
			throw new QuizAttemptException( 'attempt_already_finalized', '', $attempt );
		}

		if ( ! in_array( $question_id, $attempt->question_order, true ) ) {
			throw new QuizAttemptException( 'invalid_question_id' );
		}

		$question = $this->find_quiz_by_id( $question_id );
		if ( ! $question instanceof WP_Post || 'vl_quiz_question' !== $question->post_type ) {
			throw new QuizAttemptException( 'invalid_question_id' );
		}

		$type      = QuestionType::from_meta_value( $this->meta_string( $question_id, '_vl_question_type' ) );
		$validated = $this->validate_answer_data( $type, $raw_answer_data );
		if ( null === $validated ) {
			throw new QuizAttemptException( 'invalid_answer_data' );
		}

		$record = new QuizAnswer(
			0,
			$attempt->id,
			$question_id,
			$validated,
			null,
			null,
			$now
		);
		$this->answers->upsert( $record );

		$persisted = $this->answers->find_by_attempt_and_question( $attempt->id, $question_id );

		return new SaveAnswerResult( $attempt, $persisted, false );
	}

	/**
	 * Finalize an attempt: score, persist, and trigger course-completion
	 * re-evaluation if this was a passing final-exam.
	 *
	 * Idempotent — re-submitting an already-finalized attempt returns its
	 * current state without mutating anything.
	 *
	 * @throws QuizAttemptException
	 */
	public function submit(
		int $user_id,
		int $attempt_id,
		?\DateTimeImmutable $now = null
	): AttemptStateResult {
		$attempt = $this->attempts->find( $attempt_id );
		if ( null === $attempt ) {
			throw new QuizAttemptException( 'attempt_not_found' );
		}

		$decision = $this->gate->evaluate_for_attempt_action( $user_id, $attempt );
		if ( ! $decision->allowed ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Domain exception with controlled string code, never rendered as HTML.
			throw new QuizAttemptException( (string) $decision->reason );
		}

		if ( QuizAttemptStatus::IN_PROGRESS !== $attempt->status ) {
			return $this->build_state( $attempt, $attempt->quiz_id, false );
		}

		$effective_now = $now ?? $this->current_time_utc();
		$finalized     = $this->finalize_internal( $attempt, $effective_now, false );

		return $this->build_state( $finalized, $finalized->quiz_id, false );
	}

	/**
	 * Shared finalize path used by both `submit()` and the time-limit
	 * auto-finalize branch in `save_answer()`. `expired=true` flips the
	 * final status to EXPIRED rather than SUBMITTED.
	 */
	private function finalize_internal(
		QuizAttempt $attempt,
		\DateTimeImmutable $now,
		bool $expired_by_clock
	): QuizAttempt {
		$elapsed = $now->getTimestamp() - $attempt->started_at->getTimestamp();
		if ( $elapsed < 0 ) {
			$elapsed = 0;
		}

		$over_limit   = $attempt->time_limit_seconds > 0 && $elapsed > $attempt->time_limit_seconds;
		$time_taken   = $attempt->time_limit_seconds > 0
			? min( $elapsed, $attempt->time_limit_seconds )
			: $elapsed;
		$final_status = ( $expired_by_clock || $over_limit )
			? QuizAttemptStatus::EXPIRED
			: QuizAttemptStatus::SUBMITTED;

		$questions = $this->list_quiz_questions_in_order( $attempt->quiz_id, $attempt->question_order );
		$answers   = $this->answers->list_for_attempt( $attempt->id );
		$results   = $this->scoring->score_attempt( $questions, $answers );

		$total_score = 0;
		$by_answer   = [];
		foreach ( $answers as $answer ) {
			$result = $results[ $answer->question_id ] ?? null;
			if ( null !== $result ) {
				$by_answer[ $answer->id ] = [
					'is_correct'     => $result->is_correct,
					'points_awarded' => $result->points_awarded,
				];
			}
		}
		foreach ( $results as $result ) {
			$total_score += $result->points_awarded;
		}

		$passed = 0 === $attempt->max_score
			? false
			: ( ( $total_score / $attempt->max_score ) * 100 ) >= $attempt->passing_threshold;

		if ( [] !== $by_answer ) {
			$this->answers->update_scoring_batch( $attempt->id, $by_answer );
		}

		$this->attempts->update_final(
			$attempt->id,
			$total_score,
			$passed,
			$time_taken,
			$now,
			$final_status
		);

		$reloaded = $this->attempts->find( $attempt->id );
		if ( null === $reloaded ) {
			throw new QuizAttemptException( 'internal_error', 'Failed to read back finalized attempt.' );
		}

		// Final-exam fan-up — only on a passing SUBMITTED attempt.
		if ( QuizAttemptStatus::SUBMITTED === $final_status && true === $passed ) {
			$is_final_exam = '1' === (string) $this->meta_raw( $attempt->quiz_id, '_vl_quiz_is_final_exam' );
			if ( $is_final_exam ) {
				try {
					$this->propagator->reevaluate_course_completion( $attempt->user_id, $attempt->course_id );
				} catch ( \Throwable $e ) {
					$this->logger->error(
						'Final-exam fan-up failed; submit succeeds anyway',
						[
							'attempt_id' => $attempt->id,
							'user_id'    => $attempt->user_id,
							'course_id'  => $attempt->course_id,
							'error'      => $e->getMessage(),
						]
					);
				}
			}
		}

		return $reloaded;
	}

	private function build_state( QuizAttempt $attempt, int $quiz_id, bool $created ): AttemptStateResult {
		$questions     = $this->list_quiz_questions_in_order( $quiz_id, $attempt->question_order );
		$policy        = ShowCorrectAnswersPolicy::from_meta_value(
			$this->meta_string( $quiz_id, '_vl_quiz_show_correct_answers' )
		);
		$delivered     = $this->delivery->deliver_for_attempt_view(
			$questions,
			$policy,
			$attempt->status,
			$attempt->passed
		);
		$saved_answers = $this->answers->list_for_attempt( $attempt->id );

		return new AttemptStateResult( $attempt, $delivered, $saved_answers, $created );
	}

	/**
	 * Re-fetch the published question posts for a quiz and reorder them
	 * to match the frozen `question_order` from the attempt. Posts whose
	 * ID is not in the order are appended at the end (defensive: the
	 * snapshot should match the live set, but a question deleted post-
	 * snapshot would otherwise vanish from the wire shape).
	 *
	 * @param list<int> $order
	 *
	 * @return list<WP_Post>
	 */
	private function list_quiz_questions_in_order( int $quiz_id, array $order ): array {
		$all = $this->list_quiz_questions( $quiz_id );

		$by_id = [];
		foreach ( $all as $q ) {
			$by_id[ (int) $q->ID ] = $q;
		}

		$out = [];
		foreach ( $order as $id ) {
			if ( isset( $by_id[ $id ] ) ) {
				$out[] = $by_id[ $id ];
				unset( $by_id[ $id ] );
			}
		}
		foreach ( $by_id as $remaining ) {
			$out[] = $remaining;
		}
		return $out;
	}

	/**
	 * @param list<WP_Post> $questions
	 */
	private function compute_max_score( array $questions ): int {
		$total = 0;
		foreach ( $questions as $q ) {
			$total += $this->meta_int( (int) $q->ID, '_vl_question_points' );
		}
		return $total;
	}

	/**
	 * Validate the raw answer payload and return the canonical shape
	 * stored in `vl_quiz_answers.answer_data`. Returns `null` if the
	 * payload doesn't match the question type's expected shape.
	 *
	 * @param array<string, mixed> $raw
	 *
	 * @return array<string, mixed>|null
	 */
	private function validate_answer_data( QuestionType $type, array $raw ): ?array {
		switch ( $type ) {
			case QuestionType::SINGLE_CHOICE:
				$id = $raw['answer_id'] ?? null;
				if ( ! is_string( $id ) || '' === $id ) {
					return null;
				}
				return [ 'answer_id' => $id ];

			case QuestionType::MULTIPLE_CHOICE:
				$ids = $raw['answer_ids'] ?? null;
				if ( ! is_array( $ids ) || [] === $ids ) {
					return null;
				}
				$normalized = [];
				foreach ( $ids as $value ) {
					if ( ! is_string( $value ) || '' === $value ) {
						return null;
					}
					$normalized[] = $value;
				}
				return [ 'answer_ids' => $normalized ];

			case QuestionType::TRUE_FALSE:
				$value = $raw['value'] ?? null;
				if ( ! is_bool( $value ) ) {
					return null;
				}
				return [ 'value' => $value ];

			case QuestionType::TEXT:
				$text = $raw['text'] ?? null;
				if ( ! is_string( $text ) ) {
					return null;
				}
				$trimmed = trim( $text );
				if ( '' === $trimmed ) {
					return null;
				}
				if ( strlen( $text ) > self::TEXT_ANSWER_MAX_LEN ) {
					return null;
				}
				return [ 'text' => $text ];
		}
	}

	/**
	 * @return list<WP_Post>
	 */
	protected function list_quiz_questions( int $quiz_id ): array {
		$query = new WP_Query(
			[
				'post_type'              => 'vl_quiz_question',
				'post_parent'            => $quiz_id,
				'post_status'            => 'publish',
				'orderby'                => [
					'menu_order' => 'ASC',
					'ID'         => 'ASC',
				],
				'posts_per_page'         => -1,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			]
		);

		$out = [];
		foreach ( $query->posts as $post ) {
			if ( $post instanceof WP_Post ) {
				$out[] = $post;
			}
		}
		return $out;
	}

	protected function find_quiz_by_id( int $quiz_id ): ?WP_Post {
		$post = get_post( $quiz_id );
		return $post instanceof WP_Post ? $post : null;
	}

	protected function current_time_utc(): \DateTimeImmutable {
		return new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
	}

	private function meta_int( int $post_id, string $key ): int {
		$raw = get_post_meta( $post_id, $key, true );
		if ( is_string( $raw ) || is_int( $raw ) ) {
			$value = (int) $raw;
			return $value > 0 ? $value : 0;
		}
		return 0;
	}

	private function meta_string( int $post_id, string $key ): string {
		$raw = get_post_meta( $post_id, $key, true );
		return is_string( $raw ) ? $raw : '';
	}

	private function meta_raw( int $post_id, string $key ): mixed {
		return get_post_meta( $post_id, $key, true );
	}
}
