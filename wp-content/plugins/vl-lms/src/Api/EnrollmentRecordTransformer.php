<?php

declare(strict_types=1);

namespace VL\LMS\Api;

use VL\LMS\Catalog\Transformers\CoverImageTransformer;
use VL\LMS\Domain\Enrollment\Enrollment;
use VL\LMS\Support\PlainText;
use WP_Post;

/**
 * Projects a {@see Enrollment} domain object plus its course post into the
 * wire shape exposed by `vl/v1/enrollments`.
 *
 * Course summary is intentionally minimal — id, slug, title, cover only.
 * Price, instructors, description live on the catalog detail endpoint and
 * must not leak into enrollment records (the dashboard fetches a course
 * detail separately when it needs them).
 *
 * The `stats` block is the one enrollment-scoped addition: per-course
 * curriculum tallies computed by
 * {@see \VL\LMS\Services\Enrollment\EnrollmentStatsService} and passed in
 * by the controller, so this class stays a pure projector and the
 * single-record POST / DELETE paths pay only their own course's queries.
 * The parameter is required on purpose — a forgotten call site is a
 * fatal, not a silently stats-less record that blanks the dashboard card
 * when the store upserts it.
 *
 * Cover-image projection delegates to {@see CoverImageTransformer} so all
 * call sites emit the same `{thumbnail, card, hero, full}` shape; the
 * dashboard renders the `card` size today, but the others are kept for
 * future surfaces (e.g. a hero on a learn page).
 *
 * @author Tymofii Synianskyi
 */
// Not `final`: controller-level unit tests mock this transformer via Mockery,
// which cannot replace methods on final classes. There are no production
// subclasses today and none planned.
class EnrollmentRecordTransformer {

	public function __construct(
		private readonly CoverImageTransformer $cover,
	) {
	}

	/**
	 * @param array{
	 *     modules: array{total: int, completed: int},
	 *     lessons: array{total: int, completed: int},
	 *     topics: array{total: int, completed: int},
	 *     quizzes: array{total: int, passed: int, has_final_exam: bool}
	 * } $stats
	 *
	 * @return array{
	 *     id: int,
	 *     course: array{id: int, slug: string, title: string, cover: array<string, mixed>|null},
	 *     status: string,
	 *     source: string,
	 *     progress_pct: int,
	 *     enrolled_at: string,
	 *     completed_at: string|null,
	 *     expires_at: string|null,
	 *     stats: array{
	 *         modules: array{total: int, completed: int},
	 *         lessons: array{total: int, completed: int},
	 *         topics: array{total: int, completed: int},
	 *         quizzes: array{total: int, passed: int, has_final_exam: bool}
	 *     }
	 * }
	 */
	public function transform( Enrollment $enrollment, WP_Post $course, array $stats ): array {
		$cover_id = (int) get_post_meta( (int) $course->ID, '_vl_course_cover_image_id', true );

		return [
			'id'           => $enrollment->id,
			'course'       => [
				'id'    => (int) $course->ID,
				'slug'  => (string) $course->post_name,
				'title' => $this->title( $course ),
				'cover' => $this->cover->transform( $cover_id ),
			],
			'status'       => $enrollment->status->value,
			'source'       => $enrollment->source->value,
			'progress_pct' => $enrollment->progress_pct,
			'enrolled_at'  => $this->to_iso( $enrollment->enrolled_at ),
			'completed_at' => null === $enrollment->completed_at ? null : $this->to_iso( $enrollment->completed_at ),
			'expires_at'   => null === $enrollment->expires_at ? null : $this->to_iso( $enrollment->expires_at ),
			'stats'        => $stats,
		];
	}

	private function title( WP_Post $course ): string {
		return PlainText::from_html( (string) get_the_title( $course ) );
	}

	/**
	 * Convert a MySQL `Y-m-d H:i:s` UTC timestamp (the on-disk shape used
	 * across the enrollments table) to RFC 3339 / ISO 8601 with a `Z`
	 * suffix. Falls back to the raw string if `strtotime` cannot parse it
	 * — a bad row should not blow up the transformer.
	 */
	private function to_iso( string $mysql_datetime ): string {
		$timestamp = strtotime( $mysql_datetime . ' UTC' );
		if ( false === $timestamp ) {
			return $mysql_datetime;
		}
		return gmdate( 'Y-m-d\TH:i:s\Z', $timestamp );
	}
}
