<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Services\Assignments;

use VL\LMS\Services\Assignments\AssignmentSubmissionService;

/**
 * Test seam for {@see AssignmentSubmissionService}.
 *
 * Bypasses `get_post()` / `get_post_meta()` / clock by allowing the test
 * to set instance properties that override the protected resolver methods.
 */
class TestableAssignmentSubmissionService extends AssignmentSubmissionService {

	public ?int $resolve_course_for = 1;

	/** @var array<string, mixed> */
	public array $meta = [];

	public string $now_value = '2026-05-06 12:00:00';

	protected function resolve_course_id( int $assignment_id ): ?int {
		unset( $assignment_id );
		return $this->resolve_course_for;
	}

	// phpcs:disable Universal.NamingConventions.NoReservedKeywordParameterNames.defaultFound -- Mirrors parent signature.
	protected function meta_int( int $post_id, string $key, int $default ): int {
		unset( $post_id );
		if ( isset( $this->meta[ $key ] ) ) {
			return (int) $this->meta[ $key ];
		}
		return $default;
	}

	protected function meta_string( int $post_id, string $key, string $default ): string {
		unset( $post_id );
		if ( isset( $this->meta[ $key ] ) && is_scalar( $this->meta[ $key ] ) ) {
			return (string) $this->meta[ $key ];
		}
		return $default;
	}
	// phpcs:enable Universal.NamingConventions.NoReservedKeywordParameterNames.defaultFound

	protected function meta_bool( int $post_id, string $key ): bool {
		unset( $post_id );
		return ! empty( $this->meta[ $key ] );
	}

	protected function now(): string {
		return $this->now_value;
	}
}
