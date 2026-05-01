<?php

declare(strict_types=1);

namespace VL\LMS\Services\Zoom\Webhook\Handlers;

use VL\LMS\Services\Zoom\PostLookup;
use VL\LMS\Services\Zoom\Sync\PostMetaAccessor;
use VL\LMS\Services\Zoom\Webhook\EventHandler;
use VL\LMS\Services\Zoom\Webhook\HandlerOutcome;
use VL\LMS\Services\Zoom\Webhook\WebhookRequest;
use VL\LMS\Support\Logger;

/**
 * Handler for `meeting.started`.
 *
 * Looks up the matching `vl_session` / `vl_webinar` post and flips
 * `_vl_{kind}_status` from `scheduled` to `live`. Any other status
 * (`live`, `completed`, `cancelled`) is a no-op — Zoom occasionally
 * fires `meeting.started` after a brief network hiccup mid-meeting,
 * we don't want that to clobber the lifecycle.
 *
 * `update_post_meta` does NOT trigger `save_post`, so the Phase 7.1
 * MeetingSynchronizer stays dormant (no infinite loop).
 *
 * @author Tymofii Synianskyi
 */
final class MeetingStartedHandler implements EventHandler {

	public function __construct(
		private readonly PostLookup $lookup,
		private readonly PostMetaAccessor $meta,
		private readonly Logger $logger
	) {
	}

	public function handle( WebhookRequest $request ): HandlerOutcome {
		$meeting_id = $request->object_id();
		$found      = $this->lookup->find_by_meeting_id( $meeting_id );
		if ( null === $found ) {
			$this->logger->info( 'meeting.started for unknown meeting_id', [ 'meeting_id' => $meeting_id ] );
			return HandlerOutcome::noop( 'unknown_meeting_id', sprintf( 'No CPT post matches meeting_id %s.', $meeting_id ) );
		}

		$post_id = (int) $found->post->ID;
		$current = $this->meta->read_custom_status( $post_id, $found->kind );

		if ( 'scheduled' !== $current ) {
			return HandlerOutcome::noop(
				'status_already_advanced',
				sprintf( 'Status is "%s", not "scheduled" — leaving alone.', $current )
			);
		}

		update_post_meta( $post_id, $found->kind->meta_key_status(), 'live' );

		return HandlerOutcome::applied(
			'status_advanced_to_live',
			sprintf( 'Post %d marked live.', $post_id )
		);
	}
}
