<?php

declare(strict_types=1);

namespace VL\LMS\Services\Zoom\Webhook\Handlers;

use VL\LMS\Services\Zoom\PostLookup;
use VL\LMS\Services\Zoom\Sync\PostKind;
use VL\LMS\Services\Zoom\Webhook\EventHandler;
use VL\LMS\Services\Zoom\Webhook\HandlerOutcome;
use VL\LMS\Services\Zoom\Webhook\WebhookRequest;
use VL\LMS\Support\Logger;

/**
 * Handler for `recording.completed`.
 *
 * For sessions: writes `_vl_session_recording_url` (pointing at the
 * unauthenticated `play_url` of the first MP4 in `recording_files`).
 * Leaves `_vl_session_recording_available_until` untouched —
 * instructors set that field manually for ad-hoc sessions.
 *
 * For webinars: respects `_vl_webinar_recording_access_days`.
 *  - `0` → noop. We deliberately don't even store the URL when access
 *    is disabled.
 *  - `> 0` → writes `_vl_webinar_recording_url` AND
 *    `_vl_webinar_recording_available_until = now + access_days`.
 *
 * @author Tymofii Synianskyi
 */
final class RecordingCompletedHandler implements EventHandler {

	private \Closure $clock;

	/**
	 * @param \Closure(): \DateTimeImmutable $clock
	 */
	public function __construct(
		private readonly PostLookup $lookup,
		private readonly Logger $logger,
		?\Closure $clock = null
	) {
		$this->clock = $clock ?? static fn (): \DateTimeImmutable
			=> new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
	}

	public function handle( WebhookRequest $request ): HandlerOutcome {
		$meeting_id = $request->object_id();
		$found      = $this->lookup->find_by_meeting_id( $meeting_id );
		if ( null === $found ) {
			$this->logger->info( 'recording.completed for unknown meeting_id', [ 'meeting_id' => $meeting_id ] );
			return HandlerOutcome::noop( 'unknown_meeting_id', sprintf( 'No CPT post matches meeting_id %s.', $meeting_id ) );
		}

		$mp4_url = $this->extract_mp4_play_url( $request->payload );
		if ( null === $mp4_url ) {
			return HandlerOutcome::noop( 'no_mp4_recording', 'recording_files contained no MP4 entry.' );
		}

		$post_id = (int) $found->post->ID;

		if ( PostKind::SESSION === $found->kind ) {
			update_post_meta( $post_id, $found->kind->meta_key_recording_url(), $mp4_url );
			return HandlerOutcome::applied(
				'session_recording_stored',
				sprintf( 'Stored recording URL on session %d.', $post_id )
			);
		}

		// PostKind::WEBINAR
		$access_days = (int) get_post_meta( $post_id, '_vl_webinar_recording_access_days', true );
		if ( $access_days <= 0 ) {
			return HandlerOutcome::noop(
				'webinar_recording_disabled',
				sprintf( 'recording_access_days is 0 for webinar %d — not storing.', $post_id )
			);
		}

		update_post_meta( $post_id, $found->kind->meta_key_recording_url(), $mp4_url );

		$until_key = $found->kind->meta_key_recording_available_until();
		if ( null !== $until_key ) {
			$now   = ( $this->clock )();
			$until = $now->modify( '+' . $access_days . ' days' );
			$until = $until->setTimezone( new \DateTimeZone( 'UTC' ) );
			update_post_meta( $post_id, $until_key, $until->format( 'Y-m-d\TH:i:s\Z' ) );
		}

		return HandlerOutcome::applied(
			'webinar_recording_stored',
			sprintf( 'Stored recording URL and available_until on webinar %d.', $post_id )
		);
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private function extract_mp4_play_url( array $payload ): ?string {
		$files = $payload['recording_files'] ?? null;
		if ( ! is_array( $files ) ) {
			return null;
		}
		foreach ( $files as $file ) {
			if ( ! is_array( $file ) ) {
				continue;
			}
			$type = isset( $file['file_type'] ) && is_string( $file['file_type'] )
				? strtoupper( $file['file_type'] )
				: '';
			if ( 'MP4' !== $type ) {
				continue;
			}
			$play_url = isset( $file['play_url'] ) && is_string( $file['play_url'] )
				? $file['play_url']
				: '';
			if ( '' === $play_url ) {
				continue;
			}
			return $play_url;
		}
		return null;
	}
}
