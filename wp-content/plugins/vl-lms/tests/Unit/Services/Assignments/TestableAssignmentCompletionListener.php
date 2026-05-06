<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Services\Assignments;

use VL\LMS\Services\Assignments\AssignmentCompletionListener;

/**
 * Test seam for {@see AssignmentCompletionListener}.
 *
 * Bypasses `get_post()` so the listener's course-resolution path can be
 * exercised without booting WP.
 */
class TestableAssignmentCompletionListener extends AssignmentCompletionListener {

	public ?int $resolve_course_for = null;

	protected function resolve_course_id( int $assignment_id ): ?int {
		unset( $assignment_id );
		return $this->resolve_course_for;
	}
}
