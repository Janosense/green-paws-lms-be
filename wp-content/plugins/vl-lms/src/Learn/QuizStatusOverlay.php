<?php

declare(strict_types=1);

namespace VL\LMS\Learn;

/**
 * Keyed lookup of per-quiz attempt status for one user/course pair.
 *
 * The curriculum endpoint surfaces quizzes as tree leaves (attached at the
 * course, module, lesson, or session level). Each quiz node needs the
 * learner's standing on that quiz, but fanning out a per-quiz query during
 * the tree walk would re-introduce the N+1 the rest of {@see CurriculumTransformer}
 * avoids. Instead a single
 * {@see \VL\LMS\Repositories\QuizAttemptRepository::status_map_for_user_in_course()}
 * round trip materialises this overlay, read O(1) per leaf.
 *
 * The four-state status mirrors the curriculum rail's icon vocabulary:
 *
 *   - `passed`      — at least one submitted attempt cleared the threshold.
 *   - `in_progress` — an attempt is open (and none has passed yet).
 *   - `failed`      — every submitted attempt fell short, none open.
 *   - `not_started` — no attempt rows at all.
 *
 * `passed` outranks `in_progress`: once cleared, a retake-in-progress still
 * shows as passed because the gate is already satisfied.
 *
 * @author Tymofii Synianskyi
 */
final class QuizStatusOverlay {

	/**
	 * @param array<int, array{passed: bool, in_progress: bool, submitted_count: int, best_pct: float|null}> $by_quiz
	 *  Keyed by quiz post ID.
	 */
	public function __construct(
		private readonly array $by_quiz
	) {
	}

	/**
	 * @param array<int, array{passed: bool, in_progress: bool, submitted_count: int, best_pct: float|null}> $map
	 *  The repository's `status_map_for_user_in_course()` result.
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Mirrors PHP's BackedEnum::tryFrom() naming convention.
	public static function fromMap( array $map ): self {
		return new self( $map );
	}

	/**
	 * Resolved status string for a quiz: `passed`, `in_progress`, `failed`,
	 * or `not_started`.
	 */
	public function status( int $quiz_id ): string {
		$row = $this->by_quiz[ $quiz_id ] ?? null;
		if ( null === $row ) {
			return 'not_started';
		}
		if ( true === $row['passed'] ) {
			return 'passed';
		}
		if ( true === $row['in_progress'] ) {
			return 'in_progress';
		}
		if ( $row['submitted_count'] > 0 ) {
			return 'failed';
		}
		return 'not_started';
	}

	/**
	 * Highest passing-percentage on submitted attempts, or `null` when the
	 * learner has no scored attempt for the quiz yet.
	 */
	public function best_score_pct( int $quiz_id ): ?float {
		return $this->by_quiz[ $quiz_id ]['best_pct'] ?? null;
	}
}
