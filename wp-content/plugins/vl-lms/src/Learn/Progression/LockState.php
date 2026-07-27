<?php

declare(strict_types=1);

namespace VL\LMS\Learn\Progression;

/**
 * Why one curriculum entity is closed to one learner.
 *
 * Two reasons exist, and they carry different payloads — which is why
 * this is a value object rather than a bare string:
 *
 *   - `progression_locked` — an earlier quiz flagged
 *     `_vl_quiz_blocks_progression` has not been passed. Carries the
 *     blocking quiz so the UI can say "pass X to unlock" and link to it.
 *   - `course_quizzes_incomplete` — this quiz is flagged
 *     `_vl_quiz_requires_all_quizzes_passed` and some of the course's
 *     other non-final quizzes are still unpassed. There is no single
 *     blocking quiz to name, so it carries a count instead.
 *
 * A `null` LockState (rather than an instance with a falsy flag) is the
 * "not locked" signal everywhere — one thing to check, and an unlocked
 * state cannot accidentally carry a stale reason.
 *
 * @author Tymofii Synianskyi
 */
final class LockState {

	public const string REASON_PROGRESSION      = 'progression_locked';
	public const string REASON_COURSE_INCOMPLETE = 'course_quizzes_incomplete';

	private function __construct(
		public readonly string $reason,
		public readonly ?QuizRef $blocking_quiz,
		public readonly int $remaining_quiz_count
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
	 * @return array{
	 *     reason: string,
	 *     blocking_quiz: array{id: int, slug: string, title: string}|null,
	 *     remaining_quiz_count: int
	 * }
	 */
	public function to_array(): array {
		return [
			'reason'               => $this->reason,
			'blocking_quiz'        => $this->blocking_quiz?->to_array(),
			'remaining_quiz_count' => $this->remaining_quiz_count,
		];
	}
}
