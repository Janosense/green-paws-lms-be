<?php

declare(strict_types=1);

namespace VL\LMS\Services\Zoom\Sync;

use WP_Post;

/**
 * Decides whether a freshly-built Zoom payload differs from what was last
 * synced for a post.
 *
 * Implementation: compare {@see MeetingPayloadBuilder::fingerprint()} of
 * the fresh payload against the persisted-hash side-channel meta written
 * by {@see MeetingSynchronizer} after every successful CREATE / UPDATE.
 * That collapses per-field plumbing (topic, start_time, duration,
 * password) into a single boolean check and stays correct as the payload
 * shape grows.
 *
 * Concrete (not final) so unit tests can subclass.
 *
 * @author Tymofii Synianskyi
 */
class DiffDetector {

	private PostMetaAccessor $meta;

	public function __construct( PostMetaAccessor $meta ) {
		$this->meta = $meta;
	}

	/**
	 * @param array<string, mixed> $fresh_payload
	 */
	public function needs_update( WP_Post $post, PostKind $kind, array $fresh_payload ): bool {
		$persisted = $this->meta->read_synced_payload_hash( (int) $post->ID, $kind );
		if ( null === $persisted ) {
			return true;
		}
		return MeetingPayloadBuilder::fingerprint( $fresh_payload ) !== $persisted;
	}
}
