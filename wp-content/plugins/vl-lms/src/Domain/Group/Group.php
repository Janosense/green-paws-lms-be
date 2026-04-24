<?php

declare(strict_types=1);

namespace VL\LMS\Domain\Group;

/**
 * Immutable data carrier for one row of `{prefix}vl_groups`.
 *
 * No business rules, no DB calls. Datetime columns stay as raw UTC
 * strings (`Y-m-d H:i:s`) — consumers parse them if needed.
 *
 * @author Tymofii Synianskyi
 */
final class Group {

	public function __construct(
		public readonly int $id,
		public readonly string $name,
		public readonly string $slug,
		public readonly ?string $description,
		public readonly GroupType $type,
		public readonly int $owner_id,
		public readonly ?int $max_members,
		public readonly GroupStatus $status,
		public readonly string $created_at,
		public readonly string $updated_at
	) {
	}

	/**
	 * Hydrate from `$wpdb->get_row( ..., ARRAY_A )`.
	 *
	 * @param array<string, mixed> $row
	 *
	 * @throws \InvalidArgumentException When `type` or `status` are unrecognized.
	 */
	public static function from_row( array $row ): self {
		return new self(
			(int) $row['id'],
			(string) $row['name'],
			(string) $row['slug'],
			self::nullable_string( $row['description'] ?? null ),
			GroupType::from_string( (string) $row['type'] ),
			(int) $row['owner_id'],
			self::nullable_int( $row['max_members'] ?? null ),
			GroupStatus::from_string( (string) $row['status'] ),
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
