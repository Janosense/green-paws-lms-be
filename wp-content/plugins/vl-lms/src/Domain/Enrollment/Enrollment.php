<?php

declare(strict_types=1);

namespace VL\LMS\Domain\Enrollment;

/**
 * Immutable data carrier for one row of `{prefix}vl_enrollments`.
 *
 * Contains no business rules and performs no DB access. Datetime columns
 * are exposed as raw UTC strings (`Y-m-d H:i:s`) — consumers that need a
 * `DateTimeImmutable` parse them locally, which keeps this class free of
 * timezone allocations on every hydrate.
 *
 * @author Tymofii Synianskyi
 */
final class Enrollment {

	public function __construct(
		public readonly int $id,
		public readonly int $user_id,
		public readonly int $course_id,
		public readonly EnrollmentStatus $status,
		public readonly EnrollmentSource $source,
		public readonly ?int $source_group_id,
		public readonly ?int $source_order_id,
		public readonly string $enrolled_at,
		public readonly ?string $started_at,
		public readonly ?string $completed_at,
		public readonly ?string $expires_at,
		public readonly ?string $revoked_at,
		public readonly ?int $revoked_by,
		public readonly ?string $revoke_reason,
		public readonly int $progress_pct,
		public readonly string $created_at,
		public readonly string $updated_at
	) {
	}

	/**
	 * Hydrate from the associative array produced by
	 * `$wpdb->get_row( ..., ARRAY_A )`.
	 *
	 * Coerces MySQL's numeric-string columns to `int`, preserves `NULL` in
	 * nullable columns, and defensively clamps `progress_pct` to the 0–100
	 * range even though the `TINYINT UNSIGNED` column should already
	 * enforce it.
	 *
	 * @param array<string, mixed> $row
	 *
	 * @throws \InvalidArgumentException When `status` or `source` contain an unrecognized value.
	 */
	public static function from_row( array $row ): self {
		return new self(
			(int) $row['id'],
			(int) $row['user_id'],
			(int) $row['course_id'],
			EnrollmentStatus::from_string( (string) $row['status'] ),
			EnrollmentSource::from_string( (string) $row['source'] ),
			self::nullable_int( $row['source_group_id'] ?? null ),
			self::nullable_int( $row['source_order_id'] ?? null ),
			(string) $row['enrolled_at'],
			self::nullable_string( $row['started_at'] ?? null ),
			self::nullable_string( $row['completed_at'] ?? null ),
			self::nullable_string( $row['expires_at'] ?? null ),
			self::nullable_string( $row['revoked_at'] ?? null ),
			self::nullable_int( $row['revoked_by'] ?? null ),
			self::nullable_string( $row['revoke_reason'] ?? null ),
			self::clamp_percent( $row['progress_pct'] ?? 0 ),
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

	private static function clamp_percent( mixed $value ): int {
		$int = is_numeric( $value ) ? (int) $value : 0;
		if ( $int < 0 ) {
			return 0;
		}
		if ( $int > 100 ) {
			return 100;
		}
		return $int;
	}
}
