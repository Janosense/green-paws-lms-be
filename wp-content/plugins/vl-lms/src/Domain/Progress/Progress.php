<?php

declare(strict_types=1);

namespace VL\LMS\Domain\Progress;

/**
 * Immutable data carrier for one row of `{prefix}vl_progress`.
 *
 * No business rules and no DB access. Datetime columns are surfaced as
 * `DateTimeImmutable` (UTC) rather than the raw strings used elsewhere in
 * the domain layer — progress is read by service code (5.3) that compares
 * timestamps and computes elapsed durations, so paying the parse once at
 * hydration is cheaper than every consumer reparsing the same string.
 *
 * @author Tymofii Synianskyi
 */
final class Progress {

	public function __construct(
		public readonly int $id,
		public readonly int $user_id,
		public readonly EntityType $entity_type,
		public readonly int $entity_id,
		public readonly int $course_id,
		public readonly ProgressStatus $status,
		public readonly ?int $position_seconds,
		public readonly ?\DateTimeImmutable $completed_at,
		public readonly ?\DateTimeImmutable $last_seen_at,
		public readonly \DateTimeImmutable $created_at,
		public readonly \DateTimeImmutable $updated_at
	) {
	}

	/**
	 * Hydrate from the associative array produced by
	 * `$wpdb->get_row( ..., ARRAY_A )`.
	 *
	 * Coerces MySQL's numeric-string columns to `int`, preserves `NULL` in
	 * nullable columns, and parses every datetime as UTC.
	 *
	 * @param array<string, mixed> $row
	 *
	 * @throws \InvalidArgumentException When `entity_type` or `status` carry an unrecognized value.
	 */
	public static function from_row( array $row ): self {
		return new self(
			(int) $row['id'],
			(int) $row['user_id'],
			EntityType::from_string( (string) $row['entity_type'] ),
			(int) $row['entity_id'],
			(int) $row['course_id'],
			ProgressStatus::from_string( (string) $row['status'] ),
			self::nullable_int( $row['position_seconds'] ?? null ),
			self::nullable_datetime( $row['completed_at'] ?? null ),
			self::nullable_datetime( $row['last_seen_at'] ?? null ),
			self::datetime( (string) $row['created_at'] ),
			self::datetime( (string) $row['updated_at'] )
		);
	}

	private static function nullable_int( mixed $value ): ?int {
		if ( null === $value || '' === $value ) {
			return null;
		}
		return (int) $value;
	}

	private static function nullable_datetime( mixed $value ): ?\DateTimeImmutable {
		if ( null === $value || '' === $value ) {
			return null;
		}
		return self::datetime( (string) $value );
	}

	private static function datetime( string $value ): \DateTimeImmutable {
		return new \DateTimeImmutable( $value, new \DateTimeZone( 'UTC' ) );
	}
}
