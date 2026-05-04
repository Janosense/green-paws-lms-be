<?php

declare(strict_types=1);

namespace VL\LMS\Services\Notifications;

use VL\LMS\Mail\RecordingReadyMailer;
use VL\LMS\Repositories\EnrollmentRepository;
use VL\LMS\Repositories\WebinarRegistrationRepository;
use VL\LMS\Services\Zoom\Sync\PostKind;
use VL\LMS\Support\Logger;
use WP_Post;

/**
 * Phase 7.6 — listens to `vl_lms_recording_published` (fired by
 * {@see \VL\LMS\Services\Zoom\Webhook\Handlers\RecordingCompletedHandler}
 * after the recording URL is committed to post meta) and dispatches the
 * recording-ready email to every active recipient.
 *
 * For webinars, recipients are the active webinar registrations.
 * For sessions, recipients are the active enrollees of the parent course.
 *
 * Concrete (not final) for DI / testability.
 *
 * @author Tymofii Synianskyi
 */
class RecordingReadyListener {

	public function __construct(
		private readonly RecordingReadyMailer $mailer,
		private readonly WebinarRegistrationRepository $webinar_registrations,
		private readonly EnrollmentRepository $enrollments,
		private readonly Logger $logger
	) {
	}

	public function register(): void {
		add_action( 'vl_lms_recording_published', [ $this, 'on_recording_published' ], 10, 2 );
	}

	public function on_recording_published( int $post_id, PostKind $kind ): void {
		$user_ids = $this->resolve_recipients( $post_id, $kind );
		if ( [] === $user_ids ) {
			return;
		}

		$sent = 0;
		foreach ( $user_ids as $user_id ) {
			if ( $this->mailer->send( $post_id, $user_id, $kind ) ) {
				++$sent;
			}
		}

		$this->logger->info(
			'RecordingReadyListener: dispatched recording-ready emails.',
			[
				'post_id'    => $post_id,
				'kind'       => $kind->value,
				'recipients' => count( $user_ids ),
				'sent'       => $sent,
			]
		);
	}

	/**
	 * @return list<int>
	 */
	private function resolve_recipients( int $post_id, PostKind $kind ): array {
		if ( PostKind::WEBINAR === $kind ) {
			return $this->webinar_registrations->list_active_user_ids_for_webinar( $post_id );
		}
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return [];
		}
		$course_id = (int) $post->post_parent;
		if ( $course_id <= 0 ) {
			return [];
		}
		return $this->enrollments->list_active_user_ids_for_course( $course_id );
	}
}
