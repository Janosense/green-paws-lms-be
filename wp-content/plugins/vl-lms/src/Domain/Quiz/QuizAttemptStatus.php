<?php

declare(strict_types=1);

namespace VL\LMS\Domain\Quiz;

/**
 * Lifecycle status of a {@see QuizAttempt} row.
 *
 * Stored as the `status` column in `{prefix}vl_quiz_attempts`. `IN_PROGRESS`
 * is the start-state; `SUBMITTED` is the normal terminal state set when the
 * learner submits; `EXPIRED` is the server-detected time-limit overrun
 * variant — the same scoring write happens, only the final-status column
 * differs so analytics and the resume-attempt path can distinguish the two.
 * `ABANDONED` closes out `IN_PROGRESS` rows without scoring them. Its one
 * producer is the self-service progress reset
 * ({@see \VL\LMS\Repositories\QuizAttemptRepository::abandon_in_progress_for_user_in_course()});
 * a future cleanup sweeper for stale rows would reuse it.
 *
 * @author Tymofii Synianskyi
 */
enum QuizAttemptStatus: string {

	case IN_PROGRESS = 'in_progress';
	case SUBMITTED   = 'submitted';
	case EXPIRED     = 'expired';
	case ABANDONED   = 'abandoned';

	/**
	 * Strict parser. Rejects unknown values with a descriptive exception so
	 * callers never silently mis-type a row.
	 *
	 * @throws \InvalidArgumentException When `$value` is not a recognized case.
	 */
	public static function from_string( string $value ): self {
		$case = self::tryFrom( $value );
		if ( null === $case ) {
			$options = implode( ', ', array_map( static fn ( self $c ): string => $c->value, self::cases() ) );
			throw new \InvalidArgumentException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception, message never surfaces to end users.
				sprintf( 'Unknown quiz attempt status "%s". Valid options: %s.', $value, $options )
			);
		}
		return $case;
	}
}
