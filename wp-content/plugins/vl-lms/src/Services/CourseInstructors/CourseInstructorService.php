<?php

declare(strict_types=1);

namespace VL\LMS\Services\CourseInstructors;

use VL\LMS\Domain\CourseInstructor\CourseInstructor;
use VL\LMS\Domain\CourseInstructor\InstructorEntityType;
use VL\LMS\Domain\CourseInstructor\InstructorRole;
use VL\LMS\Repositories\CourseInstructorRepository;

/**
 * Business operations for the `vl_course_instructors` table.
 *
 * Deliberately policy-free: whether a user *should* be allowed to hold a
 * given role (WP role checks, plugin capability gates) lives in the REST
 * / admin layer that calls this service. Here we only record facts and
 * keep them internally consistent.
 *
 * Stateless — safe to construct per-request.
 *
 * @author Tymofii Synianskyi
 */
final class CourseInstructorService {

	public function __construct( private readonly CourseInstructorRepository $repo ) {
	}

	/**
	 * Assigns `$user_id` to `$entity_id` under `$role`. Idempotent — if the
	 * user is already assigned, the existing row is updated (role +
	 * display_order) and returned.
	 */
	public function add_instructor(
		InstructorEntityType $entity_type,
		int $entity_id,
		int $user_id,
		InstructorRole $role,
		int $assigned_by,
		int $display_order = 0
	): CourseInstructor {
		$existing = $this->repo->find_assignment( $entity_type, $entity_id, $user_id );
		if ( null !== $existing ) {
			$this->repo->update(
				$existing->id,
				[
					'role_in_course' => $role->value,
					'display_order'  => $display_order,
				]
			);
			return $this->require_by_id( $existing->id );
		}

		$id = $this->repo->insert(
			[
				'entity_type'    => $entity_type->value,
				'entity_id'      => $entity_id,
				'user_id'        => $user_id,
				'role_in_course' => $role->value,
				'display_order'  => $display_order,
				'assigned_at'    => gmdate( 'Y-m-d H:i:s' ),
				'assigned_by'    => $assigned_by,
			]
		);

		return $this->require_by_id( $id );
	}

	/**
	 * Deletes the `(entity, user)` row. Returns the pre-delete object, or
	 * `null` when no such row existed.
	 */
	public function remove_instructor(
		InstructorEntityType $entity_type,
		int $entity_id,
		int $user_id
	): ?CourseInstructor {
		$existing = $this->repo->find_assignment( $entity_type, $entity_id, $user_id );
		if ( null === $existing ) {
			return null;
		}
		$this->repo->delete( $existing->id );
		return $existing;
	}

	/**
	 * @throws \RuntimeException When no assignment exists for the triple.
	 */
	public function change_role(
		InstructorEntityType $entity_type,
		int $entity_id,
		int $user_id,
		InstructorRole $new_role
	): CourseInstructor {
		$existing = $this->repo->find_assignment( $entity_type, $entity_id, $user_id );
		if ( null === $existing ) {
			$entity_value = $entity_type->value;
			throw new \RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception.
				sprintf( 'No instructor assignment for user %d on %s %d.', $user_id, $entity_value, $entity_id )
			);
		}
		$this->repo->update( $existing->id, [ 'role_in_course' => $new_role->value ] );
		return $this->require_by_id( $existing->id );
	}

	/**
	 * Bulk-update `display_order` from `user_id → display_order` pairs.
	 * Silently skips users not currently assigned — the caller (typically
	 * a drag-and-drop UI) is permitted to send stale lists.
	 *
	 * @param array<int, int> $user_id_to_display_order
	 */
	public function reorder(
		InstructorEntityType $entity_type,
		int $entity_id,
		array $user_id_to_display_order
	): void {
		foreach ( $user_id_to_display_order as $user_id => $display_order ) {
			$existing = $this->repo->find_assignment( $entity_type, $entity_id, (int) $user_id );
			if ( null === $existing ) {
				continue;
			}
			$this->repo->update( $existing->id, [ 'display_order' => (int) $display_order ] );
		}
	}

	/**
	 * @return list<CourseInstructor>
	 */
	public function list_for_entity( InstructorEntityType $entity_type, int $entity_id ): array {
		return $this->repo->list_for_entity( $entity_type, $entity_id );
	}

	/**
	 * @throws \RuntimeException When a row we just wrote cannot be read back.
	 */
	private function require_by_id( int $id ): CourseInstructor {
		$row = $this->repo->find_by_id( $id );
		if ( null === $row ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception.
			throw new \RuntimeException( sprintf( 'Course instructor %d disappeared after write.', $id ) );
		}
		return $row;
	}
}
