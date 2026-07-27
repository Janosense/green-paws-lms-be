<?php

declare(strict_types=1);

namespace VL\LMS\Catalog\Detail;

use VL\LMS\Support\PlainText;
use WP_Post;

/**
 * Reshapes a single `vl_lesson` post into the curriculum summary entry.
 *
 * The summary is intentionally minimal: a four-key shape that lets the
 * marketing UI render an unenrolled outline without leaking any
 * paywalled content. Specifically, neither `_vl_lesson_video_url`,
 * `post_content`, nor `_vl_lesson_attachments` are exposed here — those
 * remain behind enrollment (Phase 4 / 5).
 *
 * @author Tymofii Synianskyi
 */
final class LessonSummaryTransformer {

	/**
	 * @return array{
	 *     id: int,
	 *     title: string,
	 *     duration_seconds: int,
	 *     is_preview: bool
	 * }
	 */
	public function transform( WP_Post $lesson ): array {
		$lesson_id = (int) $lesson->ID;

		return [
			'id'               => $lesson_id,
			'title'            => PlainText::from_html( (string) get_the_title( $lesson ) ),
			'duration_seconds' => (int) get_post_meta( $lesson_id, '_vl_lesson_duration_seconds', true ),
			'is_preview'       => (bool) get_post_meta( $lesson_id, '_vl_lesson_is_preview', true ),
		];
	}
}
