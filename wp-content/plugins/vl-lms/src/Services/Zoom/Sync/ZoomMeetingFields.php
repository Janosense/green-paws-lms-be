<?php

declare(strict_types=1);

namespace VL\LMS\Services\Zoom\Sync;

/**
 * The four Zoom-returned values written back to a CPT post on a
 * successful CREATE. Bundled into a single DTO so
 * {@see PostMetaAccessor::write_zoom_meeting_fields()} has a typed
 * contract instead of four positional strings.
 *
 * @author Tymofii Synianskyi
 */
final class ZoomMeetingFields {

	public function __construct(
		public readonly string $meeting_id,
		public readonly string $join_url,
		public readonly string $start_url,
		public readonly string $password
	) {
	}
}
