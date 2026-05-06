<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Api;

use VL\LMS\Api\AssignmentsController;
use WP_Post;

/**
 * Test seam for {@see AssignmentsController}.
 *
 * Bypasses `get_posts()` so the controller's slug-resolution path can be
 * exercised without booting WP.
 */
class TestableAssignmentsController extends AssignmentsController {

	public ?WP_Post $assignment_post = null;

	protected function find_published_assignment( string $slug ): ?WP_Post {
		unset( $slug );
		return $this->assignment_post;
	}
}
