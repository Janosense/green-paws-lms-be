<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Fixtures;

use VL\LMS\Domain\CourseInstructor\CourseInstructor;
use VL\LMS\Domain\CourseInstructor\InstructorEntityType;
use VL\LMS\Domain\CourseInstructor\InstructorRole;
use VL\LMS\Repositories\CourseInstructorRepository;

/**
 * Array-backed stand-in for {@see CourseInstructorRepository}.
 */
final class InMemoryCourseInstructorRepository extends CourseInstructorRepository {

	/** @var array<int, array<string, mixed>> */
	private array $rows = [];

	private int $next_id = 1;

	public function find_by_id( int $id ): ?CourseInstructor {
		if ( ! isset( $this->rows[ $id ] ) ) {
			return null;
		}
		return CourseInstructor::from_row( $this->rows[ $id ] );
	}

	public function find_assignment(
		InstructorEntityType $entity_type,
		int $entity_id,
		int $user_id
	): ?CourseInstructor {
		foreach ( $this->rows as $row ) {
			if (
				$row['entity_type'] === $entity_type->value
				&& (int) $row['entity_id'] === $entity_id
				&& (int) $row['user_id'] === $user_id
			) {
				return CourseInstructor::from_row( $row );
			}
		}
		return null;
	}

	/**
	 * @return list<CourseInstructor>
	 */
	public function list_for_entity( InstructorEntityType $entity_type, int $entity_id ): array {
		$matching = [];
		foreach ( $this->rows as $row ) {
			if ( $row['entity_type'] === $entity_type->value && (int) $row['entity_id'] === $entity_id ) {
				$matching[] = $row;
			}
		}

		usort(
			$matching,
			static function ( array $a, array $b ): int {
				$order = (int) $a['display_order'] <=> (int) $b['display_order'];
				return 0 !== $order ? $order : (int) $a['id'] <=> (int) $b['id'];
			}
		);

		$out = [];
		foreach ( $matching as $row ) {
			$out[] = CourseInstructor::from_row( $row );
		}
		return $out;
	}

	/**
	 * @return list<CourseInstructor>
	 */
	public function list_for_user( int $user_id ): array {
		$out = [];
		foreach ( $this->rows as $row ) {
			if ( (int) $row['user_id'] === $user_id ) {
				$out[] = CourseInstructor::from_row( $row );
			}
		}
		return $out;
	}

	public function find_lead( InstructorEntityType $entity_type, int $entity_id ): ?CourseInstructor {
		$matching = [];
		foreach ( $this->rows as $row ) {
			if (
				$row['entity_type'] === $entity_type->value
				&& (int) $row['entity_id'] === $entity_id
				&& InstructorRole::LEAD->value === $row['role_in_course']
			) {
				$matching[] = $row;
			}
		}
		if ( [] === $matching ) {
			return null;
		}
		usort(
			$matching,
			static fn ( array $a, array $b ): int => (int) $a['id'] <=> (int) $b['id']
		);
		return CourseInstructor::from_row( $matching[0] );
	}

	public function is_assigned( InstructorEntityType $entity_type, int $entity_id, int $user_id ): bool {
		return null !== $this->find_assignment( $entity_type, $entity_id, $user_id );
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function insert( array $data ): int {
		$id = $this->next_id++;

		$this->rows[ $id ] = array_merge(
			[
				'id'             => $id,
				'role_in_course' => InstructorRole::CO_INSTRUCTOR->value,
				'display_order'  => 0,
				'assigned_at'    => gmdate( 'Y-m-d H:i:s' ),
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

	public function delete_all_for_entity( InstructorEntityType $entity_type, int $entity_id ): int {
		$deleted = 0;
		foreach ( $this->rows as $id => $row ) {
			if ( $row['entity_type'] === $entity_type->value && (int) $row['entity_id'] === $entity_id ) {
				unset( $this->rows[ $id ] );
				++$deleted;
			}
		}
		return $deleted;
	}
}
