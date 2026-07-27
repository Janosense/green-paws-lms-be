<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Api;

use VL\LMS\Api\Transformers\QuizAttemptHistoryTransformer;

/**
 * Test double for {@see QuizAttemptHistoryTransformer}.
 *
 * Overrides the WP-function seams so unit tests can drive `quiz_title`,
 * `course_slug`, and `_vl_quiz_max_attempts` without round-tripping
 * through `get_the_title` / `get_post_field` / `get_post_meta`.
 */
final class TestableQuizAttemptHistoryTransformer extends QuizAttemptHistoryTransformer {

	/** @var array<int, string> */
	public array $titles = [];

	/** @var array<int, string> */
	public array $course_slugs = [];

	/** @var array<int, int> */
	public array $max_attempts_meta = [];

	protected function title( int $quiz_id ): string {
		return $this->titles[ $quiz_id ] ?? '';
	}

	protected function course_slug( int $course_id ): string {
		return $this->course_slugs[ $course_id ] ?? '';
	}

	protected function meta_int( int $post_id, string $key ): int {
		if ( '_vl_quiz_max_attempts' === $key ) {
			return $this->max_attempts_meta[ $post_id ] ?? 0;
		}
		return 0;
	}
}
