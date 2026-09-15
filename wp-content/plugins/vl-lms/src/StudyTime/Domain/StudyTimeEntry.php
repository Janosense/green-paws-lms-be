<?php

declare(strict_types=1);

namespace VL\LMS\StudyTime\Domain;

/**
 * Immutable data carrier for one row of `{prefix}vl_study_time`.
 *
 * No business rules and no DB access. Both signal columns are surfaced as
 * UTC `DateTimeImmutable`, like `Domain\Progress\Progress`.
 *
 * @author Tymofii Synianskyi
 */
final class StudyTimeEntry {

	public function __construct(
		public readonly int $id,
		public readonly int $user_id,
		public readonly int $course_id,
		public readonly string $entity_type,
		public readonly int $entity_id,
		public readonly StudyKind $kind,
		public readonly int $active_seconds,
		public readonly \DateTimeImmutable $first_signal_at,
		public readonly \DateTimeImmutable $last_signal_at
	) {
	}

	/**
	 * Hydrate from the associative array produced by
	 * `$wpdb->get_results( ..., ARRAY_A )`.
	 *
	 * Coerces MySQL's numeric-string columns to `int` and parses both
	 * datetimes as UTC.
	 *
	 * @param array<string, mixed> $row
	 *
	 * @throws \ValueError When `kind` carries an unrecognized value.
	 */
	public static function from_row( array $row ): self {
		return new self(
			(int) $row['id'],
			(int) $row['user_id'],
			(int) $row['course_id'],
			(string) $row['entity_type'],
			(int) $row['entity_id'],
			StudyKind::from( (string) $row['kind'] ),
			(int) $row['active_seconds'],
			self::datetime( (string) $row['first_signal_at'] ),
			self::datetime( (string) $row['last_signal_at'] )
		);
	}

	private static function datetime( string $value ): \DateTimeImmutable {
		return new \DateTimeImmutable( $value, new \DateTimeZone( 'UTC' ) );
	}
}
