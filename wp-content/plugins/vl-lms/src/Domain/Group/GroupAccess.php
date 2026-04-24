<?php

declare(strict_types=1);

namespace VL\LMS\Domain\Group;

/**
 * Immutable data carrier for one row of `{prefix}vl_group_access`.
 *
 * Represents a grant that lets every active member of `group_id` reach
 * `entity_id` of `entity_type`. The actual per-user enrollments are
 * synthesized by {@see \VL\LMS\Services\Groups\GroupEnrollmentService}.
 *
 * @author Tymofii Synianskyi
 */
final class GroupAccess {

	public function __construct(
		public readonly int $id,
		public readonly int $group_id,
		public readonly AccessEntityType $entity_type,
		public readonly int $entity_id,
		public readonly AccessType $access_type,
		public readonly string $granted_at,
		public readonly int $granted_by,
		public readonly ?string $expires_at
	) {
	}

	/**
	 * @param array<string, mixed> $row
	 *
	 * @throws \InvalidArgumentException When `entity_type` or `access_type` are unrecognized.
	 */
	public static function from_row( array $row ): self {
		return new self(
			(int) $row['id'],
			(int) $row['group_id'],
			AccessEntityType::from_string( (string) $row['entity_type'] ),
			(int) $row['entity_id'],
			AccessType::from_string( (string) $row['access_type'] ),
			(string) $row['granted_at'],
			(int) $row['granted_by'],
			self::nullable_string( $row['expires_at'] ?? null )
		);
	}

	private static function nullable_string( mixed $value ): ?string {
		if ( null === $value ) {
			return null;
		}
		return (string) $value;
	}
}
