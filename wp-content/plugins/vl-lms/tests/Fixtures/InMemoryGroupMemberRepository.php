<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Fixtures;

use VL\LMS\Domain\Group\GroupMember;
use VL\LMS\Domain\Group\MemberRole;
use VL\LMS\Repositories\GroupMemberRepository;

/**
 * Array-backed stand-in for {@see GroupMemberRepository}.
 */
final class InMemoryGroupMemberRepository extends GroupMemberRepository {

	/** @var array<int, array<string, mixed>> */
	private array $rows = [];

	private int $next_id = 1;

	public function find_by_id( int $id ): ?GroupMember {
		if ( ! isset( $this->rows[ $id ] ) ) {
			return null;
		}
		return GroupMember::from_row( $this->rows[ $id ] );
	}

	public function find_active( int $group_id, int $user_id ): ?GroupMember {
		foreach ( $this->rows as $row ) {
			if (
				(int) $row['group_id'] === $group_id
				&& (int) $row['user_id'] === $user_id
				&& null === $row['left_at']
			) {
				return GroupMember::from_row( $row );
			}
		}
		return null;
	}

	/**
	 * @return list<GroupMember>
	 */
	public function list_active_members( int $group_id ): array {
		$out = [];
		foreach ( $this->rows as $row ) {
			if ( (int) $row['group_id'] === $group_id && null === $row['left_at'] ) {
				$out[] = GroupMember::from_row( $row );
			}
		}
		return $out;
	}

	/**
	 * @return list<GroupMember>
	 */
	public function list_active_memberships_for_user( int $user_id ): array {
		$out = [];
		foreach ( $this->rows as $row ) {
			if ( (int) $row['user_id'] === $user_id && null === $row['left_at'] ) {
				$out[] = GroupMember::from_row( $row );
			}
		}
		return $out;
	}

	public function count_active_members( int $group_id ): int {
		return count( $this->list_active_members( $group_id ) );
	}

	/**
	 * @param list<int> $user_ids
	 * @return array<int, list<GroupMember>>
	 */
	public function list_active_for_users( array $user_ids ): array {
		if ( [] === $user_ids ) {
			return [];
		}
		$out = [];
		foreach ( $this->rows as $row ) {
			if ( null !== $row['left_at'] ) {
				continue;
			}
			$user_id = (int) $row['user_id'];
			if ( ! in_array( $user_id, $user_ids, true ) ) {
				continue;
			}
			$out[ $user_id ] ??= [];
			$out[ $user_id ][] = GroupMember::from_row( $row );
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function insert( array $data ): int {
		$id = $this->next_id++;

		$this->rows[ $id ] = array_merge(
			[
				'id'            => $id,
				'role_in_group' => MemberRole::MEMBER->value,
				'joined_at'     => gmdate( 'Y-m-d H:i:s' ),
				'left_at'       => null,
			],
			$data,
			[ 'id' => $id ]
		);

		return $id;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function update( int $id, array $data ): bool {
		if ( ! isset( $this->rows[ $id ] ) ) {
			return false;
		}
		$this->rows[ $id ] = array_merge( $this->rows[ $id ], $data );
		return true;
	}

	public function mark_left( int $id, ?string $left_at = null ): bool {
		if ( ! isset( $this->rows[ $id ] ) ) {
			return false;
		}
		$this->rows[ $id ]['left_at'] = $left_at ?? gmdate( 'Y-m-d H:i:s' );
		return true;
	}
}
