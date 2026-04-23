<?php

declare(strict_types=1);

namespace VL\LMS\Access;

/**
 * Co-instructor lookup that always returns `false`.
 *
 * Placeholder implementation used until the `vl_course_instructors` table
 * and its repository land. Keep this class — it is also useful in tests
 * that want the filter wired without asserting any co-instructor state.
 *
 * @author Tymofii Synianskyi
 */
final class NullCoInstructorLookup implements CoInstructorLookupInterface {

	public function is_co_instructor( int $user_id, int $post_id ): bool {
		return false;
	}
}
