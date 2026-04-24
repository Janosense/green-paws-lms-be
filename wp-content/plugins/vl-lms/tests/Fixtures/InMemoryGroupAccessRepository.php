<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Fixtures;

use VL\LMS\Domain\Group\AccessEntityType;
use VL\LMS\Domain\Group\AccessType;
use VL\LMS\Domain\Group\GroupAccess;
use VL\LMS\Repositories\GroupAccessRepository;

/**
 * Array-backed stand-in for {@see GroupAccessRepository}.
 */
final class InMemoryGroupAccessRepository extends GroupAccessRepository {

	/** @var array<int, array<string, mixed>> */
	private array $rows = [];

	private int $next_id = 1;

	public function find_by_id( int $id ): ?GroupAccess {
		if ( ! isset( $this->rows[ $id ] ) ) {
			return null;
		}
		return GroupAccess::from_row( $this->rows[ $id ] );
	}

	public function find_by_group_entity( int $group_id, AccessEntityType $entity_type, int $entity_id ): ?GroupAccess {
		foreach ( $this->rows as $row ) {
			if (
				(int) $row['group_id'] === $group_id
				&& $row['entity_type'] === $entity_type->value
				&& (int) $row['entity_id'] === $entity_id
			) {
				return GroupAccess::from_row( $row );
			}
		}
		return null;
	}

	/**
	 * @return list<GroupAccess>
	 */
	public function list_by_group( int $group_id ): array {
		$out = [];
		foreach ( $this->rows as $row ) {
			if ( (int) $row['group_id'] === $group_id ) {
				$out[] = GroupAccess::from_row( $row );
			}
		}
		return $out;
	}

	/**
	 * @return list<GroupAccess>
	 */
	public function list_active_for_group( int $group_id ): array {
		$now = gmdate( 'Y-m-d H:i:s' );
		$out = [];
		foreach ( $this->rows as $row ) {
			if ( (int) $row['group_id'] !== $group_id ) {
				continue;
			}
			if ( null !== $row['expires_at'] && $row['expires_at'] <= $now ) {
				continue;
			}
			$out[] = GroupAccess::from_row( $row );
		}
		return $out;
	}

	/**
	 * @return list<GroupAccess>
	 */
	public function list_by_entity( AccessEntityType $entity_type, int $entity_id ): array {
		$out = [];
		foreach ( $this->rows as $row ) {
			if ( $row['entity_type'] === $entity_type->value && (int) $row['entity_id'] === $entity_id ) {
				$out[] = GroupAccess::from_row( $row );
			}
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
				'id'          => $id,
				'access_type' => AccessType::GRANTED->value,
				'granted_at'  => gmdate( 'Y-m-d H:i:s' ),
				'expires_at'  => null,
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

	public function delete( int $id ): bool {
		if ( ! isset( $this->rows[ $id ] ) ) {
			return false;
		}
		unset( $this->rows[ $id ] );
		return true;
	}
}
