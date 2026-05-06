<?php

declare(strict_types=1);

namespace VL\LMS\Domain\Assignment;

/**
 * Lifecycle status of a {@see Submission} row.
 *
 * `PENDING` is the start-state set on create / re-submit; `GRADED` and
 * `REJECTED` are the two terminal states an admin grade-or-reject action
 * produces. Re-opening a graded/rejected submission is out of scope in
 * Phase 9.4 — the submit path treats both terminal states as locked.
 *
 * @author Tymofii Synianskyi
 */
enum SubmissionStatus: string {

	case PENDING  = 'pending';
	case GRADED   = 'graded';
	case REJECTED = 'rejected';

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
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception, never surfaces to end users.
				sprintf( 'Unknown submission status "%s". Valid options: %s.', $value, $options )
			);
		}
		return $case;
	}
}
