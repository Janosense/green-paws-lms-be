<?php

declare(strict_types=1);

namespace VL\LMS\Domain\CourseInstructor;

/**
 * Informational label for a user's role on a course or webinar.
 *
 * Does NOT gate access — any row in `vl_course_instructors` grants edit
 * rights regardless of role. The value is for display and record-keeping
 * (e.g. "lead instructor" vs. "guest lecturer" on the catalog page).
 *
 * Exactly one row per entity carries `LEAD` at any given time — that row
 * mirrors the `post_author`, maintained by
 * {@see \VL\LMS\Services\CourseInstructors\AuthorSyncService}.
 *
 * @author Tymofii Synianskyi
 */
enum InstructorRole: string {

	case LEAD          = 'lead';
	case CO_INSTRUCTOR = 'co_instructor';
	case ASSISTANT     = 'assistant';
	case GUEST         = 'guest';

	/**
	 * @throws \InvalidArgumentException When `$value` is not a recognized case.
	 */
	public static function from_string( string $value ): self {
		$case = self::tryFrom( $value );
		if ( null === $case ) {
			$options = implode( ', ', array_map( static fn ( self $c ): string => $c->value, self::cases() ) );
			throw new \InvalidArgumentException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception, message never surfaces to end users.
				sprintf( 'Unknown instructor role "%s". Valid options: %s.', $value, $options )
			);
		}
		return $case;
	}
}
