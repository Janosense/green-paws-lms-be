<?php

declare(strict_types=1);

namespace VL\LMS\Quiz\Scoring;

/**
 * Per-question scoring outcome produced by a scorer.
 *
 * Two fields, both immutable: whether the answer was correct (or, for
 * multiple-choice all-or-nothing, whether the full required set was hit)
 * and the integer points awarded. The scoring engine collects one of
 * these per question and uses them to drive the attempt-level totals.
 *
 * @author Tymofii Synianskyi
 */
final class ScoringResult {

	public function __construct(
		public readonly bool $is_correct,
		public readonly int $points_awarded
	) {
	}
}
