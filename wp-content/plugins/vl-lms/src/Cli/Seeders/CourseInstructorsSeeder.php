<?php

declare(strict_types=1);

namespace VL\LMS\Cli\Seeders;

use VL\LMS\Cli\SeederContext;
use VL\LMS\Cli\SeederResult;
use VL\LMS\Domain\CourseInstructor\InstructorEntityType;
use VL\LMS\Domain\CourseInstructor\InstructorRole;
use VL\LMS\Repositories\CourseInstructorRepository;
use VL\LMS\Services\CourseInstructors\CourseInstructorService;

/**
 * Adds `co_instructor` rows for half of the seeded courses.
 *
 * The `lead` row is created automatically by
 * {@see \VL\LMS\Services\CourseInstructors\AuthorSyncService} on every
 * `wp_insert_post` for `vl_course` / `vl_webinar` (via `post_author`), so
 * this seeder only fans out the secondary instructor relationship.
 *
 * Idempotency comes from {@see CourseInstructorService::add_instructor()},
 * which performs a `find_assignment` lookup and updates the existing row
 * when one is present. So re-running the seeder produces no duplicates.
 *
 * @author Tymofii Synianskyi
 */
final class CourseInstructorsSeeder {

	public function __construct(
		private readonly CourseInstructorService $service,
		private readonly CourseInstructorRepository $repo
	) {
	}

	/**
	 * @param list<array{slug:string,id:int,type:string,modules:list<array<string,mixed>>,sessions:list<int>,quiz:?array<string,mixed>}> $courses
	 * @param array<string,int>                                                                                                          $instructor_ids Login → user ID
	 *
	 * @return SeederResult
	 */
	public function run( SeederContext $context, array $courses, array $instructor_ids ): SeederResult {
		$result = new SeederResult();

		$instructor_logins = array_keys( $instructor_ids );
		$instructor_count  = count( $instructor_logins );
		if ( $instructor_count < 2 ) {
			return $result;
		}

		$admin_id = $this->resolve_assigner_id();

		foreach ( $courses as $course_index => $course ) {
			if ( 1 !== $course_index % 2 ) {
				continue;
			}

			$lead         = $this->repo->find_lead( InstructorEntityType::COURSE, $course['id'] );
			$lead_user_id = null === $lead ? 0 : $lead->user_id;

			$co_login = '';
			foreach ( $instructor_logins as $login ) {
				if ( 0 === $lead_user_id || $instructor_ids[ $login ] !== $lead_user_id ) {
					$co_login = $login;
					break;
				}
			}
			if ( '' === $co_login ) {
				continue;
			}

			$co_user_id = $instructor_ids[ $co_login ];

			$existing = $this->repo->find_assignment( InstructorEntityType::COURSE, $course['id'], $co_user_id );
			if ( null !== $existing && InstructorRole::CO_INSTRUCTOR === $existing->role_in_course ) {
				++$result->skipped;
				continue;
			}

			$this->service->add_instructor(
				InstructorEntityType::COURSE,
				$course['id'],
				$co_user_id,
				InstructorRole::CO_INSTRUCTOR,
				$admin_id,
				1
			);

			++$result->created;
		}

		$context->log(
			sprintf(
				/* translators: 1: created count, 2: skipped count. */
				__( 'Course co-instructors: %1$d created, %2$d skipped.', 'vl-lms' ),
				$result->created,
				$result->skipped
			)
		);

		return $result;
	}

	private function resolve_assigner_id(): int {
		$current = get_current_user_id();
		if ( $current > 0 ) {
			return $current;
		}
		$admins = get_users(
			[
				'role'   => 'administrator',
				'number' => 1,
				'fields' => 'ID',
			]
		);
		if ( is_array( $admins ) && [] !== $admins ) {
			return (int) $admins[0];
		}
		return 0;
	}
}
