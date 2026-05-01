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
 * Handler for `meeting.ended`.
 *
 * Flips `_vl_{kind}_status` to `completed`. Allowed source states:
 * `live` and `scheduled` (Zoom occasionally reports an end without a
 * matching start when a host opens then immediately closes the room).
 * `cancelled` and `completed` are no-ops.
 *
 * @author Tymofii Synianskyi
 */
final class MeetingEndedHandler implements EventHandler {

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
			$this->logger->info( 'meeting.ended for unknown meeting_id', [ 'meeting_id' => $meeting_id ] );
			return HandlerOutcome::noop( 'unknown_meeting_id', sprintf( 'No CPT post matches meeting_id %s.', $meeting_id ) );
		}

		$post_id = (int) $found->post->ID;
		$current = $this->meta->read_custom_status( $post_id, $found->kind );

		if ( ! in_array( $current, [ 'scheduled', 'live' ], true ) ) {
			return HandlerOutcome::noop(
				'status_already_terminal',
				sprintf( 'Status is "%s" — leaving alone.', $current )
			);
		}

		update_post_meta( $post_id, $found->kind->meta_key_status(), 'completed' );

		return HandlerOutcome::applied(
			'status_advanced_to_completed',
			sprintf( 'Post %d marked completed.', $post_id )
		);
	}
}
