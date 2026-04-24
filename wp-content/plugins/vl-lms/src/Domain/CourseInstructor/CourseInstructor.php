<?php

declare(strict_types=1);

namespace VL\LMS\Domain\CourseInstructor;

/**
 * Immutable data carrier for one row of `{prefix}vl_course_instructors`.
 *
 * No business rules, no DB calls. The `assigned_at` column stays as a
 * raw UTC string (`Y-m-d H:i:s`) — consumers parse it if they need a
 * `DateTimeImmutable`.
 *
 * @author Tymofii Synianskyi
 */
final class CourseInstructor {

	public function __construct(
		public readonly int $id,
		public readonly InstructorEntityType $entity_type,
		public readonly int $entity_id,
		public readonly int $user_id,
		public readonly InstructorRole $role_in_course,
		public readonly int $display_order,
		public readonly string $assigned_at,
		public readonly int $assigned_by
	) {
	}

	/**
	 * Hydrate from `$wpdb->get_row( ..., ARRAY_A )`.
	 *
	 * @param array<string, mixed> $row
	 *
	 * @throws \InvalidArgumentException When `entity_type` or `role_in_course` are unrecognized.
	 */
	public static function from_row( array $row ): self {
		return new self(
			(int) $row['id'],
			InstructorEntityType::from_string( (string) $row['entity_type'] ),
			(int) $row['entity_id'],
			(int) $row['user_id'],
			InstructorRole::from_string( (string) $row['role_in_course'] ),
			(int) $row['display_order'],
			(string) $row['assigned_at'],
			(int) $row['assigned_by']
		);
	}
}
