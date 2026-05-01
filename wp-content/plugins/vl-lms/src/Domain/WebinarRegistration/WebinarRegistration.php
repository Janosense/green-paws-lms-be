<?php

declare(strict_types=1);

namespace VL\LMS\Domain\WebinarRegistration;

/**
 * Immutable data carrier for one row of `{prefix}vl_webinar_registrations`.
 *
 * Tracks a user's registration state for a `vl_webinar` post plus their
 * eventual live-attendance fan-in (`attended`,
 * `attended_duration_seconds`) which the Phase 7.2 webinar attendance
 * handler accumulates from Zoom webhook events.
 *
 * Datetime columns are exposed as raw UTC strings (`Y-m-d H:i:s`) — same
 * pattern as {@see \VL\LMS\Domain\Enrollment\Enrollment}.
 *
 * @author Tymofii Synianskyi
 */
class WebinarRegistration {

	public function __construct(
		public readonly int $id,
		public readonly int $webinar_id,
		public readonly int $user_id,
		public readonly WebinarRegistrationStatus $status,
		public readonly WebinarRegistrationSource $source,
		public readonly string $registered_at,
		public readonly ?string $cancelled_at,
		public readonly bool $attended,
		public readonly int $attended_duration_seconds,
		public readonly string $created_at,
		public readonly string $updated_at
	) {
	}

	/**
	 * Hydrate from the associative array produced by
	 * `$wpdb->get_row( ..., ARRAY_A )`.
	 *
	 * @param array<string, mixed> $row
	 *
	 * @throws \InvalidArgumentException When `status` or `source` carry an unrecognized value.
	 */
	public static function from_row( array $row ): self {
		return new self(
			(int) $row['id'],
			(int) $row['webinar_id'],
			(int) $row['user_id'],
			WebinarRegistrationStatus::from_string( (string) $row['status'] ),
			WebinarRegistrationSource::from_string( (string) $row['source'] ),
			(string) $row['registered_at'],
			self::nullable_string( $row['cancelled_at'] ?? null ),
			(bool) (int) ( $row['attended'] ?? 0 ),
			(int) ( $row['attended_duration_seconds'] ?? 0 ),
			(string) $row['created_at'],
			(string) $row['updated_at']
		);
	}

	private static function nullable_string( mixed $value ): ?string {
		if ( null === $value ) {
			return null;
		}
		return (string) $value;
	}
}
