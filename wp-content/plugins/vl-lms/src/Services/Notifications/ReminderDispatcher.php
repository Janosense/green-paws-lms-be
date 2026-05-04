<?php

declare(strict_types=1);

namespace VL\LMS\Services\Notifications;

use Closure;
use VL\LMS\Mail\SessionReminderMailer;
use VL\LMS\Mail\WebinarReminderMailer;
use VL\LMS\Repositories\EnrollmentRepository;
use VL\LMS\Repositories\WebinarRegistrationRepository;
use VL\LMS\Services\Zoom\Sync\PostKind;
use VL\LMS\Support\Logger;
use WP_Post;

/**
 * Phase 7.6 — invoked by WP-Cron at the times planned by
 * {@see ReminderScheduler}.
 *
 * Re-validates the post on dispatch (status, future-ness, existence) so
 * a session that was cancelled or moved between the planning time and
 * the cron firing window does not surface a confusing email. Then fans
 * out the variant-specific reminder to every active recipient.
 *
 * Concrete (not final) for DI / testability.
 *
 * @author Tymofii Synianskyi
 */
class ReminderDispatcher {

	/** @var Closure(): \DateTimeImmutable */
	private readonly Closure $clock;

	/**
	 * @param Closure(): \DateTimeImmutable $clock
	 */
	public function __construct(
		private readonly SessionReminderMailer $session_mailer,
		private readonly WebinarReminderMailer $webinar_mailer,
		private readonly WebinarRegistrationRepository $webinar_registrations,
		private readonly EnrollmentRepository $enrollments,
		private readonly Logger $logger,
		Closure $clock
	) {
		$this->clock = $clock;
	}

	public function dispatch( int $post_id, string $kind_value, string $variant ): void {
		$kind = PostKind::tryFrom( $kind_value );
		if ( null === $kind ) {
			$this->logger->warning( 'ReminderDispatcher: unknown post kind.', [ 'kind' => $kind_value ] );
			return;
		}
		if ( ! in_array( $variant, [ '24h', '1h' ], true ) ) {
			$this->logger->warning( 'ReminderDispatcher: unknown variant.', [ 'variant' => $variant ] );
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || $kind->value !== $post->post_type || 'publish' !== $post->post_status ) {
			return;
		}

		$status    = (string) get_post_meta( $post_id, $kind->meta_key_status(), true );
		$start_raw = (string) get_post_meta( $post_id, $kind->meta_key_scheduled_start(), true );
		if ( 'cancelled' === $status || '' === $start_raw ) {
			return;
		}

		try {
			$start = new \DateTimeImmutable( $start_raw );
		} catch ( \Throwable ) {
			return;
		}

		$now = ( $this->clock )();
		if ( $now > $start ) {
			// Cron drifted past the planned target; sending a "starts in 1h"
			// reminder after the event already started would surface a
			// confusing message — bail.
			return;
		}

		$user_ids = $this->resolve_recipients( $post_id, $kind );
		if ( [] === $user_ids ) {
			return;
		}

		$sent = 0;
		foreach ( $user_ids as $user_id ) {
			if ( $this->send_to_user( $post_id, $user_id, $kind, $variant ) ) {
				++$sent;
			}
		}

		$this->logger->info(
			'ReminderDispatcher: dispatched reminders.',
			[
				'post_id'    => $post_id,
				'kind'       => $kind->value,
				'variant'    => $variant,
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
		// Sessions: fan out to all course enrollees.
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

	private function send_to_user( int $post_id, int $user_id, PostKind $kind, string $variant ): bool {
		if ( PostKind::WEBINAR === $kind ) {
			return $this->webinar_mailer->send( $post_id, $user_id, $variant );
		}
		return $this->session_mailer->send( $post_id, $user_id, $variant );
	}
}
