<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Admin\StudyTime;

use VL\LMS\Admin\StudyTime\AnalyticsSection;
use VL\LMS\StudyTime\Reports\StudyTimeReportQuery;

/**
 * Bypasses the section's one `WP_Query` so `render()` runs without
 * WordPress — the seam `AnalyticsSection` is non-`final` for.
 */
final class TestableAnalyticsSection extends AnalyticsSection {

	/**
	 * @param array<int, string> $courses `id => title`, in the order the
	 *                                    real query would return them.
	 */
	public function __construct( StudyTimeReportQuery $reports, private readonly array $courses = [] ) {
		parent::__construct( $reports );
	}

	/**
	 * @return array<int, string>
	 */
	protected function published_courses(): array {
		return $this->courses;
	}
}
