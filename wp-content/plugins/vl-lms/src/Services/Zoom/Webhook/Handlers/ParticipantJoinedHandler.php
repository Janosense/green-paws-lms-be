<?php

declare(strict_types=1);

namespace VL\LMS\Services\Zoom\Webhook\Handlers;

use VL\LMS\Repositories\SessionAttendanceRepository;
use VL\LMS\Services\Zoom\PostLookup;
use VL\LMS\Services\Zoom\Sync\PostKind;
use VL\LMS\Services\Zoom\Webhook\EventHandler;
use VL\LMS\Services\Zoom\Webhook\HandlerException;
use VL\LMS\Services\Zoom\Webhook\HandlerOutcome;
use VL\LMS\Services\Zoom\Webhook\WebhookRequest;
use VL\LMS\Services\Zoom\Webhook\WebinarJoinTracker;
use VL\LMS\Support\Logger;

/**
 * Handler for `meeting.participant_joined`.
 *
 * Branches on `PostKind`:
 * - `SESSION`: writes a row into `vl_session_attendance` via
 *   {@see SessionAttendanceRepository::record_join()} (idempotent on
 *   `(session_id, zoom_participant_uuid)`).
 * - `WEBINAR`: stashes the open join in
 *   {@see WebinarJoinTracker::record_open()} so the matching
 *   `participant_left` can compute duration.
 *
 * @author Tymofii Synianskyi
 */
final class ParticipantJoinedHandler implements EventHandler {

	public function __construct(
		private readonly PostLookup $lookup,
		private readonly SessionAttendanceRepository $session_attendance,
		private readonly WebinarJoinTracker $webinar_tracker,
		private readonly Logger $logger
	) {
	}

	public function handle( WebhookRequest $request ): HandlerOutcome {
		$meeting_id = $request->object_id();
		$found      = $this->lookup->find_by_meeting_id( $meeting_id );
		if ( null === $found ) {
			$this->logger->info( 'participant_joined for unknown meeting_id', [ 'meeting_id' => $meeting_id ] );
			return HandlerOutcome::noop( 'unknown_meeting_id', sprintf( 'No CPT post matches meeting_id %s.', $meeting_id ) );
		}

		$participant = $request->payload['participant'] ?? null;
		if ( ! is_array( $participant ) ) {
			throw new HandlerException( 'participant_joined payload is missing the "participant" object.' );
		}

		$uuid = isset( $participant['participant_uuid'] ) && is_string( $participant['participant_uuid'] )
			? $participant['participant_uuid']
			: '';
		if ( '' === $uuid ) {
			throw new HandlerException( 'participant_joined payload is missing participant_uuid.' );
		}

		$email = isset( $participant['email'] ) && is_string( $participant['email'] )
			? trim( $participant['email'] )
			: '';
		$name  = isset( $participant['user_name'] ) && is_string( $participant['user_name'] )
			? trim( $participant['user_name'] )
			: '';

		$join_time_raw = isset( $participant['join_time'] ) && is_string( $participant['join_time'] )
			? $participant['join_time']
			: '';
		try {
			$joined_at = '' === $join_time_raw
				? new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) )
				: new \DateTimeImmutable( $join_time_raw );
			$joined_at = $joined_at->setTimezone( new \DateTimeZone( 'UTC' ) );
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception, message never surfaces to end users.
			throw new HandlerException( 'Could not parse participant join_time: ' . $e->getMessage() );
		}

		$post_id = (int) $found->post->ID;

		if ( PostKind::SESSION === $found->kind ) {
			$user_id = $this->resolve_user_id( $email );
			$this->session_attendance->record_join(
				$post_id,
				$user_id,
				$uuid,
				'' === $email ? null : $email,
				'' === $name ? null : $name,
				$joined_at
			);

			// Phase 7.4: notify the progress fan-in. The course id is the
			// session's `post_parent`. Listeners (SessionAttendanceProgressListener)
			// short-circuit when `$user_id` is null.
			$course_id = (int) $found->post->post_parent;
			do_action(
				'vl_lms_session_attendance_recorded',
				$post_id,
				$user_id,
				$course_id
			);

			return HandlerOutcome::applied(
				'session_join_recorded',
				sprintf( 'Recorded join for session %d, participant %s.', $post_id, $uuid )
			);
		}

		// PostKind::WEBINAR
		$this->webinar_tracker->record_open( $post_id, $uuid, $joined_at );
		return HandlerOutcome::applied(
			'webinar_join_tracked',
			sprintf( 'Tracked open join for webinar %d, participant %s.', $post_id, $uuid )
		);
	}

	private function resolve_user_id( string $email ): ?int {
		if ( '' === $email ) {
			return null;
		}
		$user = get_user_by( 'email', $email );
		if ( false === $user ) {
			return null;
		}
		$id = (int) $user->ID;
		return $id > 0 ? $id : null;
	}
}
