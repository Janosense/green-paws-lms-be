<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Admin\Analytics;

use VL\LMS\Admin\Analytics\AnalyticsPage;

/**
 * Bypasses the page's one `$wpdb` read so `render()` can be exercised
 * without a database — the seam `AnalyticsPage` is non-`final` for
 * (`docs/TESTING.md` → Rules the suite enforces).
 */
final class TestableAnalyticsPage extends AnalyticsPage {

	/**
	 * @param list<array<string, mixed>> $rows
	 */
	public function __construct( private readonly array $rows = [] ) {
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	protected function fetch_last_30_days(): array {
		return $this->rows;
	}
}
