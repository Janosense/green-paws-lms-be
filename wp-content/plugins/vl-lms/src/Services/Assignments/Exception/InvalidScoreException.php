<?php

declare(strict_types=1);

namespace VL\LMS\Services\Assignments\Exception;

/**
 * Thrown when a grader supplies a score outside `[0, max_score]`.
 *
 * @author Tymofii Synianskyi
 */
class InvalidScoreException extends \RuntimeException {

	public function __construct(
		public readonly int $score,
		public readonly int $max_score
	) {
		parent::__construct(
			sprintf( 'Score %d is outside the valid range [0, %d].', $score, $max_score )
		);
	}
}
