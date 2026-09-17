<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Admin\Dashboard;

use VL\LMS\Admin\Dashboard\InstructorDashboardPage;
use WP_Post;

/**
 * Bypasses the page's two `WP_Query` seams so `render()` runs without
 * WordPress — the reason `InstructorDashboardPage` is non-`final`
 * (`docs/TESTING.md` → Rules the suite enforces).
 */
final class TestableInstructorDashboardPage extends InstructorDashboardPage {

	/**
	 * @var list<WP_Post>
	 */
	private array $courses = [];

	/**
	 * @param list<WP_Post> $courses
	 */
	public function with_courses( array $courses ): self {
		$this->courses = $courses;
		return $this;
	}

	/**
	 * @return list<WP_Post>
	 */
	protected function resolve_courses( int $user_id ): array {
		return $this->courses;
	}

	/**
	 * No preview link in tests: the page falls back to «—», which keeps the
	 * markup assertions about the hook's own effect.
	 */
	protected function first_published_child( int $parent_id, string $post_type ): ?WP_Post {
		return null;
	}
}
