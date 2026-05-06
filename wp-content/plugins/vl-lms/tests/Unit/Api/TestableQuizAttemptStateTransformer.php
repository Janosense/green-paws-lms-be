<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Api;

use VL\LMS\Api\Transformers\QuizAttemptStateTransformer;

/**
 * Test double for {@see QuizAttemptStateTransformer}.
 *
 * Overrides the WP-function seams so unit tests can drive
 * `quiz_title`, `_vl_quiz_max_attempts`, and the wall-clock without
 * round-tripping through `get_the_title` / `get_post_meta` / `time()`.
 */
final class TestableQuizAttemptStateTransformer extends QuizAttemptStateTransformer {

	/** @var array<int, string> */
	public array $titles = [];

	/** @var array<int, int> */
	public array $max_attempts_meta = [];

	public int $now = 1746500000;

	protected function title( int $quiz_id ): string {
		return $this->titles[ $quiz_id ] ?? '';
	}

	protected function meta_int( int $post_id, string $key ): int {
		if ( '_vl_quiz_max_attempts' === $key ) {
			return $this->max_attempts_meta[ $post_id ] ?? 0;
		}
		return 0;
	}

	protected function now(): int {
		return $this->now;
	}
}
