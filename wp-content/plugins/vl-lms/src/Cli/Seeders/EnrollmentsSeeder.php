<?php

declare(strict_types=1);

namespace VL\LMS\Cli\Seeders;

use VL\LMS\Cli\SeederContext;
use VL\LMS\Cli\SeederResult;
use VL\LMS\Domain\Enrollment\EnrollmentSource;
use VL\LMS\Services\Enrollment\EnrollmentService;

/**
 * Inserts the 7 demo enrollments per §6.8 of the seeder spec.
 *
 * Each row is materialized through {@see EnrollmentService::enroll()} so
 * the production write path runs end-to-end (idempotent insert + status
 * preservation). Status flips to `completed` happen later, in
 * {@see ProgressSeeder}, via the natural completion fan-up — never directly
 * here.
 *
 * `EnrollmentSource::ADMIN` does not exist in the source enum; the closest
 * documented case is `MANUAL`, which is used here for every demo row.
 * Surfaced in the run log when this seeder runs.
 *
 * @author Tymofii Synianskyi
 */
final class EnrollmentsSeeder {

	public function __construct( private readonly EnrollmentService $service ) {
	}

	/**
	 * @param array<string,int>                  $student_ids Login → user ID
	 * @param array<int,int>                     $course_index_to_id Course index (0-based) → post ID
	 * @param list<array<string,mixed>>          $course_records (kept for parity with caller)
	 *
	 * @return array{summary: SeederResult, enrollments: list<array{student:string,user_id:int,course_index:int,course_id:int,plan:string}>}
	 */
	public function run(
		SeederContext $context,
		array $student_ids,
		array $course_index_to_id,
		array $course_records
	): array {
		unset( $course_records );

		$summary     = new SeederResult();
		$plan        = $this->plan();
		$enrollments = [];

		foreach ( $plan as $row ) {
			$student_login = $row['student_login'];
			$user_id       = $student_ids[ $student_login ] ?? 0;
			$course_id     = $course_index_to_id[ $row['course_index'] ] ?? 0;

			if ( 0 === $user_id || 0 === $course_id ) {
				++$summary->failed;
				$summary->messages[] = sprintf(
					/* translators: 1: student login, 2: course index. */
					__( 'Cannot enroll %1$s into course #%2$d: missing IDs.', 'vl-lms' ),
					$student_login,
					$row['course_index'] + 1
				);
				continue;
			}

			$existing_before = $this->service->has_active_access( $user_id, $course_id );

			$this->service->enroll( $user_id, $course_id, EnrollmentSource::MANUAL );

			if ( $existing_before ) {
				++$summary->skipped;
			} else {
				++$summary->created;
			}

			$enrollments[] = [
				'student'      => $student_login,
				'user_id'      => $user_id,
				'course_index' => $row['course_index'],
				'course_id'    => $course_id,
				'plan'         => $row['plan'],
			];
		}

		$context->log(
			sprintf(
				/* translators: 1: created count, 2: skipped count. */
				__( 'Enrollments: %1$d created, %2$d skipped (existing).', 'vl-lms' ),
				$summary->created,
				$summary->skipped
			)
		);

		return [
			'summary'     => $summary,
			'enrollments' => $enrollments,
		];
	}

	/**
	 * @return list<array{student_login:string, course_index:int, plan:string}>
	 */
	private function plan(): array {
		return [
			[
				'student_login' => 'student.bohdan',
				'course_index'  => 0,
				'plan'          => 'completed',
			],
			[
				'student_login' => 'student.bohdan',
				'course_index'  => 3,
				'plan'          => 'in_progress_25',
			],
			[
				'student_login' => 'student.sofia',
				'course_index'  => 1,
				'plan'          => 'in_progress_60_with_resume',
			],
			[
				'student_login' => 'student.sofia',
				'course_index'  => 2,
				'plan'          => 'just_started_5',
			],
			[
				'student_login' => 'student.sofia',
				'course_index'  => 4,
				'plan'          => 'in_progress_60',
			],
			[
				'student_login' => 'student.dmytro',
				'course_index'  => 5,
				'plan'          => 'completed',
			],
			[
				'student_login' => 'student.dmytro',
				'course_index'  => 0,
				'plan'          => 'in_progress_25',
			],
		];
	}
}
