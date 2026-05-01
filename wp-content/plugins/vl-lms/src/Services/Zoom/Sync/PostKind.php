<?php

declare(strict_types=1);

namespace VL\LMS\Services\Zoom\Sync;

/**
 * Discriminator for the two CPTs participating in Zoom meeting sync.
 *
 * Each case knows the post-type slug it represents and the meta keys it
 * reads / writes — that keeps {@see PostMetaAccessor},
 * {@see MeetingPayloadBuilder}, and {@see DiffDetector} from each
 * carrying their own copy of the meta-key table. Adding a future kind
 * (e.g. group-coaching post type) is a single switch update per accessor.
 *
 * @author Tymofii Synianskyi
 */
enum PostKind: string {

	case SESSION = 'vl_session';
	case WEBINAR = 'vl_webinar';

	/**
	 * Lenient parser. Returns `null` for unrelated post types so callers
	 * can short-circuit on `save_post` for posts the synchronizer
	 * doesn't care about.
	 */
	public static function from_post_type( string $post_type ): ?self {
		return self::tryFrom( $post_type );
	}

	public function meta_key_status(): string {
		return match ( $this ) {
			self::SESSION => '_vl_session_status',
			self::WEBINAR => '_vl_webinar_status',
		};
	}

	public function meta_key_scheduled_start(): string {
		return match ( $this ) {
			self::SESSION => '_vl_session_scheduled_start',
			self::WEBINAR => '_vl_webinar_scheduled_start',
		};
	}

	public function meta_key_scheduled_end(): string {
		return match ( $this ) {
			self::SESSION => '_vl_session_scheduled_end',
			self::WEBINAR => '_vl_webinar_scheduled_end',
		};
	}

	public function meta_key_zoom_meeting_id(): string {
		return match ( $this ) {
			self::SESSION => '_vl_session_zoom_meeting_id',
			self::WEBINAR => '_vl_webinar_zoom_meeting_id',
		};
	}

	public function meta_key_zoom_join_url(): string {
		return match ( $this ) {
			self::SESSION => '_vl_session_zoom_join_url',
			self::WEBINAR => '_vl_webinar_zoom_join_url',
		};
	}

	public function meta_key_zoom_start_url(): string {
		return match ( $this ) {
			self::SESSION => '_vl_session_zoom_start_url',
			self::WEBINAR => '_vl_webinar_zoom_start_url',
		};
	}

	public function meta_key_zoom_password(): string {
		return match ( $this ) {
			self::SESSION => '_vl_session_zoom_password',
			self::WEBINAR => '_vl_webinar_zoom_password',
		};
	}

	/**
	 * Internal-only meta carrying a fingerprint of the last successfully
	 * synced payload. Powers {@see DiffDetector}; not user-editable, not
	 * registered via {@see \VL\LMS\CPT\AbstractCptRegistrar} (we
	 * deliberately keep it out of the documented meta surface so no
	 * external integration grows a dependency on it).
	 */
	public function meta_key_synced_payload_hash(): string {
		return match ( $this ) {
			self::SESSION => '_vl_session_zoom_synced_payload_hash',
			self::WEBINAR => '_vl_webinar_zoom_synced_payload_hash',
		};
	}
}
