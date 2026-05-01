<?php

declare(strict_types=1);

namespace VL\LMS\Services\Zoom;

use VL\LMS\Services\Zoom\Sync\PostKind;
use WP_Post;

/**
 * Result of {@see PostLookup::find_by_meeting_id()}. Carries both the
 * resolved `WP_Post` and its discriminated `PostKind`, so handlers don't
 * have to re-derive the kind from `post_type`.
 *
 * @author Tymofii Synianskyi
 */
final class LookupResult {

	public function __construct(
		public readonly WP_Post $post,
		public readonly PostKind $kind
	) {
	}
}
