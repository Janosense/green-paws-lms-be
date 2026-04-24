<?php

declare(strict_types=1);

namespace VL\LMS\Access;

use VL\LMS\Domain\CourseInstructor\InstructorEntityType;
use VL\LMS\Repositories\CourseInstructorRepository;

/**
 * Table-backed {@see CoInstructorLookupInterface} — resolves
 * "is this user a registered instructor of this post?" against the
 * `vl_course_instructors` table.
 *
 * Per-instance memoization cuts down on duplicate queries: `map_meta_cap`
 * fires repeatedly for the same `(user, post)` pair on list screens and
 * single-post screens, and this lookup is the hot path for every such
 * check. The cache lives for the lifetime of the instance and is not
 * intended as a persistent layer — a fresh instance is built per boot,
 * per test, per long-running CLI job.
 *
 * @author Tymofii Synianskyi
 */
final class TableBackedCoInstructorLookup implements CoInstructorLookupInterface {

	/**
	 * @var array<string, bool>
	 */
	private array $cache = [];

	public function __construct( private readonly CourseInstructorRepository $repo ) {
	}

	public function is_co_instructor( int $user_id, int $post_id ): bool {
		if ( $user_id <= 0 || $post_id <= 0 ) {
			return false;
		}

		$cache_key = $post_id . '|' . $user_id;
		if ( array_key_exists( $cache_key, $this->cache ) ) {
			return $this->cache[ $cache_key ];
		}

		$entity_type = $this->resolve_entity_type( $post_id );
		if ( null === $entity_type ) {
			$this->cache[ $cache_key ] = false;
			return false;
		}

		$result                    = $this->repo->is_assigned( $entity_type, $post_id, $user_id );
		$this->cache[ $cache_key ] = $result;

		return $result;
	}

	private function resolve_entity_type( int $post_id ): ?InstructorEntityType {
		$post_type = get_post_type( $post_id );
		if ( ! is_string( $post_type ) ) {
			return null;
		}

		return match ( $post_type ) {
			'vl_course'  => InstructorEntityType::COURSE,
			'vl_webinar' => InstructorEntityType::WEBINAR,
			default      => null,
		};
	}
}
