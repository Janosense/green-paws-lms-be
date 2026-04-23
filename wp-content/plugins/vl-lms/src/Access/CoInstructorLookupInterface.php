<?php

declare(strict_types=1);

namespace VL\LMS\Access;

/**
 * Contract for resolving co-instructor relationships between users and posts.
 *
 * Implementations answer a single yes/no question: is the given user
 * registered as a co-instructor of the given post? The interface is
 * intentionally storage-agnostic — callers must not assume a database
 * table, a meta key, or any other persistence shape.
 *
 * @author Tymofii Synianskyi
 */
interface CoInstructorLookupInterface {

	/**
	 * Whether `$user_id` is a co-instructor of `$post_id`.
	 *
	 * Returns `true` iff the user is currently registered as a
	 * co-instructor of the post. The post author is NOT a co-instructor —
	 * authorship is a separate relation handled by WordPress core.
	 */
	public function is_co_instructor( int $user_id, int $post_id ): bool;
}
