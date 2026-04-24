<?php

declare(strict_types=1);

namespace VL\LMS\Domain\Group;

/**
 * Immutable data carrier for one row of `{prefix}vl_group_members`.
 *
 * A member who leaves gets `left_at` populated; the row is never deleted,
 * so re-joining creates a new row and the historical row stays as an
 * audit trail. {@see self::is_active()} abstracts that null-check.
 *
 * @author Tymofii Synianskyi
 */
final class GroupMember {

	public function __construct(
		public readonly int $id,
		public readonly int $group_id,
		public readonly int $user_id,
		public readonly MemberRole $role_in_group,
		public readonly string $joined_at,
		public readonly ?string $left_at
	) {
	}

	public function is_active(): bool {
		return null === $this->left_at;
	}

	/**
	 * @param array<string, mixed> $row
	 *
	 * @throws \InvalidArgumentException When `role_in_group` is unrecognized.
	 */
	public static function from_row( array $row ): self {
		return new self(
			(int) $row['id'],
			(int) $row['group_id'],
			(int) $row['user_id'],
			MemberRole::from_string( (string) $row['role_in_group'] ),
			(string) $row['joined_at'],
			self::nullable_string( $row['left_at'] ?? null )
		);
	}

	private static function nullable_string( mixed $value ): ?string {
		if ( null === $value ) {
			return null;
		}
		return (string) $value;
	}
}
