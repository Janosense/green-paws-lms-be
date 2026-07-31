<?php

declare(strict_types=1);

namespace VL\LMS\Learn\Progression;

/**
 * Why one curriculum entity is closed to one learner.
 *
 * Three reasons exist, and they carry different payloads — which is why
 * this is a value object rather than a bare string:
 *
 *   - `progression_locked` — an earlier quiz flagged
 *     `_vl_quiz_blocks_progression` has not been passed. Carries the
 *     blocking quiz so the UI can say "pass X to unlock" and link to it.
 *   - `course_quizzes_incomplete` — this quiz is flagged
 *     `_vl_quiz_requires_all_quizzes_passed` and some of the course's
 *     other non-final quizzes are still unpassed. There is no single
 *     blocking quiz to name, so it carries a count instead.
 *   - `previous_incomplete` — the course is in sequential completion
 *     mode and an earlier lesson or topic has not been marked complete.
 *     Carries that entity so the UI can say "finish X to unlock".
 *
 * A `null` LockState (rather than an instance with a falsy flag) is the
 * "not locked" signal everywhere — one thing to check, and an unlocked
 * state cannot accidentally carry a stale reason.
 *
 * @author Tymofii Synianskyi
 */
final class LockState {

	public const string REASON_PROGRESSION         = 'progression_locked';
	public const string REASON_COURSE_INCOMPLETE   = 'course_quizzes_incomplete';
	public const string REASON_PREVIOUS_INCOMPLETE = 'previous_incomplete';

	private function __construct(
		public readonly string $reason,
		public readonly ?QuizRef $blocking_quiz,
		public readonly int $remaining_quiz_count,
		public readonly ?EntityRef $blocking_entity = null
	) {
	}

	/**
	 * Locked behind a specific unpassed blocking quiz.
	 */
	public static function progression( QuizRef $blocking_quiz ): self {
		return new self( self::REASON_PROGRESSION, $blocking_quiz, 0 );
	}

	/**
	 * Locked until the course's remaining non-final quizzes are passed.
	 */
	public static function course_quizzes_incomplete( int $remaining ): self {
		return new self( self::REASON_COURSE_INCOMPLETE, null, $remaining );
	}

	/**
	 * Locked behind an earlier lesson or topic the learner has not marked
	 * complete (sequential completion mode).
	 */
	public static function previous_incomplete( EntityRef $blocking_entity ): self {
		return new self( self::REASON_PREVIOUS_INCOMPLETE, null, 0, $blocking_entity );
	}

	/**
	 * @return array{
	 *     reason: string,
	 *     blocking_quiz: array{id: int, slug: string, title: string}|null,
	 *     remaining_quiz_count: int,
	 *     blocking_entity: array{kind: string, id: int, slug: string, title: string}|null
	 * }
	 */
	public function to_array(): array {
		return [
			'reason'               => $this->reason,
			'blocking_quiz'        => $this->blocking_quiz?->to_array(),
			'remaining_quiz_count' => $this->remaining_quiz_count,
			'blocking_entity'      => $this->blocking_entity?->to_array(),
		];
	}
}
