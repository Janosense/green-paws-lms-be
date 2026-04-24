<?php

declare(strict_types=1);

namespace VL\LMS\Services\Groups;

use VL\LMS\Domain\Group\AccessEntityType;
use VL\LMS\Domain\Group\AccessType;
use VL\LMS\Domain\Group\Group;
use VL\LMS\Domain\Group\GroupAccess;
use VL\LMS\Domain\Group\GroupMember;
use VL\LMS\Domain\Group\GroupStatus;
use VL\LMS\Domain\Group\GroupType;
use VL\LMS\Domain\Group\MemberRole;
use VL\LMS\Repositories\GroupAccessRepository;
use VL\LMS\Repositories\GroupMemberRepository;
use VL\LMS\Repositories\GroupRepository;

/**
 * CRUD business layer over the three group tables.
 *
 * Knows nothing about enrollments — fan-out of access/membership events
 * to individual enrollment rows is the job of
 * {@see GroupEnrollmentService}. Keeping the responsibilities split means
 * a REST controller orchestrating "grant access then fan out" can decide
 * how to sequence the two operations (e.g. inside a single transaction)
 * without entangling the CRUD layer with fan-out rules.
 *
 * Stateless — safe to construct per-request.
 *
 * @author Tymofii Synianskyi
 */
final class GroupService {

	public function __construct(
		private readonly GroupRepository $groups,
		private readonly GroupMemberRepository $members,
		private readonly GroupAccessRepository $access
	) {
	}

	/**
	 * Inserts a new active group.
	 *
	 * @throws \InvalidArgumentException On empty name or slug.
	 * @throws \RuntimeException         When the slug already exists.
	 */
	public function create_group(
		string $name,
		string $slug,
		int $owner_id,
		GroupType $type = GroupType::AD_HOC,
		?string $description = null,
		?int $max_members = null
	): Group {
		if ( '' === trim( $name ) ) {
			throw new \InvalidArgumentException( 'Group name cannot be empty.' );
		}
		if ( '' === trim( $slug ) ) {
			throw new \InvalidArgumentException( 'Group slug cannot be empty.' );
		}

		if ( null !== $this->groups->find_by_slug( $slug ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception.
			throw new \RuntimeException( sprintf( 'Group with slug "%s" already exists.', $slug ) );
		}

		$id = $this->groups->insert(
			[
				'name'        => $name,
				'slug'        => $slug,
				'description' => $description,
				'type'        => $type->value,
				'owner_id'    => $owner_id,
				'max_members' => $max_members,
				'status'      => GroupStatus::ACTIVE->value,
			]
		);

		return $this->require_group( $id );
	}

	/**
	 * @throws \RuntimeException When the group does not exist.
	 */
	public function archive_group( int $group_id ): Group {
		$this->require_group( $group_id );
		$this->groups->update( $group_id, [ 'status' => GroupStatus::ARCHIVED->value ] );
		return $this->require_group( $group_id );
	}

	/**
	 * @throws \RuntimeException         When the group does not exist.
	 * @throws \InvalidArgumentException On empty name.
	 */
	public function rename_group( int $group_id, string $new_name ): Group {
		if ( '' === trim( $new_name ) ) {
			throw new \InvalidArgumentException( 'Group name cannot be empty.' );
		}
		$this->require_group( $group_id );
		$this->groups->update( $group_id, [ 'name' => $new_name ] );
		return $this->require_group( $group_id );
	}

	/**
	 * Idempotent — returns the active membership unchanged if one exists.
	 *
	 * @throws \RuntimeException When `max_members` is set and reached.
	 */
	public function add_member( int $group_id, int $user_id, MemberRole $role = MemberRole::MEMBER ): GroupMember {
		$existing = $this->members->find_active( $group_id, $user_id );
		if ( null !== $existing ) {
			return $existing;
		}

		$group = $this->require_group( $group_id );
		if ( null !== $group->max_members && $this->members->count_active_members( $group_id ) >= $group->max_members ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception.
			throw new \RuntimeException( sprintf( 'Group %d is at capacity (%d).', $group_id, $group->max_members ) );
		}

		$id = $this->members->insert(
			[
				'group_id'      => $group_id,
				'user_id'       => $user_id,
				'role_in_group' => $role->value,
				'joined_at'     => gmdate( 'Y-m-d H:i:s' ),
			]
		);

		$member = $this->members->find_by_id( $id );
		if ( null === $member ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception.
			throw new \RuntimeException( sprintf( 'Group member %d disappeared after insert.', $id ) );
		}
		return $member;
	}

	/**
	 * Marks the user's active membership as left. Returns `null` when the
	 * user has no active membership in the group (idempotent).
	 */
	public function remove_member( int $group_id, int $user_id ): ?GroupMember {
		$existing = $this->members->find_active( $group_id, $user_id );
		if ( null === $existing ) {
			return null;
		}

		$this->members->mark_left( $existing->id );
		return $this->members->find_by_id( $existing->id );
	}

	/**
	 * Idempotent — returns the existing access row unchanged if one exists
	 * for `(group, entity_type, entity_id)`.
	 */
	public function grant_access(
		int $group_id,
		AccessEntityType $entity_type,
		int $entity_id,
		int $granted_by,
		AccessType $type = AccessType::GRANTED,
		?string $expires_at = null
	): GroupAccess {
		$existing = $this->access->find_by_group_entity( $group_id, $entity_type, $entity_id );
		if ( null !== $existing ) {
			return $existing;
		}

		$id = $this->access->insert(
			[
				'group_id'    => $group_id,
				'entity_type' => $entity_type->value,
				'entity_id'   => $entity_id,
				'access_type' => $type->value,
				'granted_at'  => gmdate( 'Y-m-d H:i:s' ),
				'granted_by'  => $granted_by,
				'expires_at'  => $expires_at,
			]
		);

		$row = $this->access->find_by_id( $id );
		if ( null === $row ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception.
			throw new \RuntimeException( sprintf( 'Group access %d disappeared after insert.', $id ) );
		}
		return $row;
	}

	/**
	 * Deletes the `(group, entity_type, entity_id)` row. Returns the
	 * pre-deletion object so callers (typically a fan-out orchestrator)
	 * can react to the removal, or `null` when no such row existed.
	 */
	public function revoke_access( int $group_id, AccessEntityType $entity_type, int $entity_id ): ?GroupAccess {
		$existing = $this->access->find_by_group_entity( $group_id, $entity_type, $entity_id );
		if ( null === $existing ) {
			return null;
		}

		$this->access->delete( $existing->id );
		return $existing;
	}

	/**
	 * @throws \RuntimeException When the group does not exist.
	 */
	private function require_group( int $group_id ): Group {
		$group = $this->groups->find_by_id( $group_id );
		if ( null === $group ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception.
			throw new \RuntimeException( sprintf( 'Group %d not found.', $group_id ) );
		}
		return $group;
	}
}
