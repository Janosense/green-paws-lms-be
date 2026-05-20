<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Fixtures;

use VL\LMS\Domain\Group\Group;
use VL\LMS\Domain\Group\GroupStatus;
use VL\LMS\Repositories\GroupRepository;

/**
 * Array-backed stand-in for {@see GroupRepository} — extends the real
 * repository, overrides every public method with in-memory state.
 */
final class InMemoryGroupRepository extends GroupRepository {

	/** @var array<int, array<string, mixed>> */
	private array $rows = [];

	private int $next_id = 1;

	public function find_by_id( int $id ): ?Group {
		if ( ! isset( $this->rows[ $id ] ) ) {
			return null;
		}
		return Group::from_row( $this->rows[ $id ] );
	}

	public function find_by_slug( string $slug ): ?Group {
		foreach ( $this->rows as $row ) {
			if ( $row['slug'] === $slug ) {
				return Group::from_row( $row );
			}
		}
		return null;
	}

	/**
	 * @return list<Group>
	 */
	public function list_by_owner( int $owner_id, ?GroupStatus $status = null ): array {
		$out = [];
		foreach ( $this->rows as $row ) {
			if ( (int) $row['owner_id'] !== $owner_id ) {
				continue;
			}
			if ( null !== $status && $row['status'] !== $status->value ) {
				continue;
			}
			$out[] = Group::from_row( $row );
		}
		return $out;
	}

	/**
	 * @return list<Group>
	 */
	public function list_by_status( GroupStatus $status ): array {
		$out = [];
		foreach ( $this->rows as $row ) {
			if ( $row['status'] === $status->value ) {
				$out[] = Group::from_row( $row );
			}
		}
		return $out;
	}

	public function count_by_owner( int $owner_id, ?GroupStatus $status = null ): int {
		return count( $this->list_by_owner( $owner_id, $status ) );
	}

	/**
	 * @return list<Group>
	 */
	public function paginate( ?GroupStatus $status, ?string $search, int $limit, int $offset ): array {
		$rows = $this->filter( $status, $search );
		usort( $rows, static fn ( array $a, array $b ): int => strcmp( (string) $b['created_at'], (string) $a['created_at'] ) );
		$page = array_slice( $rows, $offset, $limit );

		$out = [];
		foreach ( $page as $row ) {
			$out[] = Group::from_row( $row );
		}
		return $out;
	}

	public function count( ?GroupStatus $status, ?string $search ): int {
		return count( $this->filter( $status, $search ) );
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function filter( ?GroupStatus $status, ?string $search ): array {
		$needle = null === $search ? '' : strtolower( trim( $search ) );

		$out = [];
		foreach ( $this->rows as $row ) {
			if ( null !== $status && $row['status'] !== $status->value ) {
				continue;
			}
			if ( '' !== $needle ) {
				$haystack = strtolower( (string) ( $row['name'] ?? '' ) . ' ' . (string) ( $row['slug'] ?? '' ) );
				if ( false === strpos( $haystack, $needle ) ) {
					continue;
				}
			}
			$out[] = $row;
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function insert( array $data ): int {
		$now = gmdate( 'Y-m-d H:i:s' );
		$id  = $this->next_id++;

		$this->rows[ $id ] = array_merge(
			[
				'id'          => $id,
				'description' => null,
				'max_members' => null,
				'status'      => GroupStatus::ACTIVE->value,
				'created_at'  => $now,
				'updated_at'  => $now,
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
		$data['updated_at'] = gmdate( 'Y-m-d H:i:s' );
		$this->rows[ $id ]  = array_merge( $this->rows[ $id ], $data );
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
