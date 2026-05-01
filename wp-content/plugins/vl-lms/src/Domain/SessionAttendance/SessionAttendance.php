<?php

declare(strict_types=1);

namespace VL\LMS\Domain\SessionAttendance;

/**
 * Immutable data carrier for one row of `{prefix}vl_session_attendance`.
 *
 * Records a single Zoom participant's join → leave window for a `vl_session`
 * meeting. `zoom_participant_uuid` is Zoom's stable per-(meeting,
 * participant) identifier — it stays constant across a single user's
 * join/leave/rejoin within the same meeting, so the unique
 * `(session_id, zoom_participant_uuid)` index drives the idempotent
 * record-join path.
 *
 * Datetime columns are exposed as raw UTC strings (`Y-m-d H:i:s`) — this
 * mirrors the {@see \VL\LMS\Domain\Enrollment\Enrollment} pattern and
 * keeps hydration cheap.
 *
 * @author Tymofii Synianskyi
 */
class SessionAttendance {

	public function __construct(
		public readonly int $id,
		public readonly int $session_id,
		public readonly ?int $user_id,
		public readonly string $zoom_participant_uuid,
		public readonly ?string $participant_email,
		public readonly ?string $participant_name,
		public readonly string $joined_at,
		public readonly ?string $left_at,
		public readonly ?int $duration_seconds,
		public readonly string $created_at,
		public readonly string $updated_at
	) {
	}

	/**
	 * Hydrate from the associative array produced by
	 * `$wpdb->get_row( ..., ARRAY_A )`.
	 *
	 * @param array<string, mixed> $row
	 */
	public static function from_row( array $row ): self {
		return new self(
			(int) $row['id'],
			(int) $row['session_id'],
			self::nullable_int( $row['user_id'] ?? null ),
			(string) $row['zoom_participant_uuid'],
			self::nullable_string( $row['participant_email'] ?? null ),
			self::nullable_string( $row['participant_name'] ?? null ),
			(string) $row['joined_at'],
			self::nullable_string( $row['left_at'] ?? null ),
			self::nullable_int( $row['duration_seconds'] ?? null ),
			(string) $row['created_at'],
			(string) $row['updated_at']
		);
	}

	private static function nullable_int( mixed $value ): ?int {
		if ( null === $value || '' === $value ) {
			return null;
		}
		return (int) $value;
	}

	private static function nullable_string( mixed $value ): ?string {
		if ( null === $value ) {
			return null;
		}
		return (string) $value;
	}
}
