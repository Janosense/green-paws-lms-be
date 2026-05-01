<?php

declare(strict_types=1);

namespace VL\LMS\Services\Zoom\Webhook\Handlers;

use VL\LMS\Repositories\SessionAttendanceRepository;
use VL\LMS\Repositories\WebinarRegistrationRepository;
use VL\LMS\Services\Zoom\PostLookup;
use VL\LMS\Services\Zoom\Sync\PostKind;
use VL\LMS\Services\Zoom\Webhook\EventHandler;
use VL\LMS\Services\Zoom\Webhook\HandlerException;
use VL\LMS\Services\Zoom\Webhook\HandlerOutcome;
use VL\LMS\Services\Zoom\Webhook\WebhookRequest;
use VL\LMS\Services\Zoom\Webhook\WebinarJoinTracker;
use VL\LMS\Support\Logger;

/**
 * Handler for `meeting.participant_left`.
 *
 * For sessions: closes the open `vl_session_attendance` row, computing
 * `duration_seconds`. An orphan leave (no matching open row) becomes a
 * logged no-op.
 *
 * For webinars: consumes the open-join transient via
 * {@see WebinarJoinTracker::consume()} → duration in seconds. If the
 * joiner's email maps to a registered user, the duration delta is added
 * to `attended_duration_seconds` via
 * {@see WebinarRegistrationRepository::mark_attended()}. Anonymous
 * joiners (no email match) are noops.
 *
 * @author Tymofii Synianskyi
 */
final class ParticipantLeftHandler implements EventHandler {

	public function __construct(
		private readonly PostLookup $lookup,
		private readonly SessionAttendanceRepository $session_attendance,
		private readonly WebinarJoinTracker $webinar_tracker,
		private readonly WebinarRegistrationRepository $webinar_registrations,
		private readonly Logger $logger
	) {
	}

	public function handle( WebhookRequest $request ): HandlerOutcome {
		$meeting_id = $request->object_id();
		$found      = $this->lookup->find_by_meeting_id( $meeting_id );
		if ( null === $found ) {
			$this->logger->info( 'participant_left for unknown meeting_id', [ 'meeting_id' => $meeting_id ] );
			return HandlerOutcome::noop( 'unknown_meeting_id', sprintf( 'No CPT post matches meeting_id %s.', $meeting_id ) );
		}

		$participant = $request->payload['participant'] ?? null;
		if ( ! is_array( $participant ) ) {
			throw new HandlerException( 'participant_left payload is missing the "participant" object.' );
		}

		$uuid = isset( $participant['participant_uuid'] ) && is_string( $participant['participant_uuid'] )
			? $participant['participant_uuid']
			: '';
		if ( '' === $uuid ) {
			throw new HandlerException( 'participant_left payload is missing participant_uuid.' );
		}

		$leave_time_raw = isset( $participant['leave_time'] ) && is_string( $participant['leave_time'] )
			? $participant['leave_time']
			: '';
		try {
			$left_at = '' === $leave_time_raw
				? new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) )
				: new \DateTimeImmutable( $leave_time_raw );
			$left_at = $left_at->setTimezone( new \DateTimeZone( 'UTC' ) );
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception, message never surfaces to end users.
			throw new HandlerException( 'Could not parse participant leave_time: ' . $e->getMessage() );
		}

		$post_id = (int) $found->post->ID;

		if ( PostKind::SESSION === $found->kind ) {
			$closed = $this->session_attendance->record_leave( $post_id, $uuid, $left_at );
			if ( null === $closed ) {
				$this->logger->warning(
					'orphan participant_left for session',
					[
						'session_id'       => $post_id,
						'participant_uuid' => $uuid,
					]
				);
				return HandlerOutcome::noop( 'orphan_leave_session', 'No open attendance row to close.' );
			}
			return HandlerOutcome::applied(
				'session_leave_recorded',
				sprintf( 'Closed attendance row for session %d, participant %s.', $post_id, $uuid )
			);
		}

		// PostKind::WEBINAR
		$duration = $this->webinar_tracker->consume( $post_id, $uuid, $left_at );
		if ( null === $duration ) {
			$this->logger->warning(
				'orphan participant_left for webinar',
				[
					'webinar_id'       => $post_id,
					'participant_uuid' => $uuid,
				]
			);
			return HandlerOutcome::noop( 'orphan_leave_webinar', 'No open-join transient found.' );
		}

		$email = isset( $participant['email'] ) && is_string( $participant['email'] )
			? trim( $participant['email'] )
			: '';
		if ( '' === $email ) {
			return HandlerOutcome::noop( 'webinar_anonymous_leave', 'Joiner had no resolvable email — duration not credited.' );
		}

		$user = get_user_by( 'email', $email );
		if ( false === $user || (int) $user->ID <= 0 ) {
			return HandlerOutcome::noop( 'webinar_anonymous_leave', 'Joiner email did not match a known user.' );
		}
		$user_id = (int) $user->ID;

		$registration = $this->webinar_registrations->find_active( $post_id, $user_id );
		if ( null === $registration ) {
			return HandlerOutcome::noop( 'webinar_unregistered_leave', 'Joiner is not actively registered.' );
		}

		$this->webinar_registrations->mark_attended( $post_id, $user_id, $duration );

		return HandlerOutcome::applied(
			'webinar_attendance_credited',
			sprintf( 'Credited %ds to webinar %d for user %d.', $duration, $post_id, $user_id )
		);
	}
}
