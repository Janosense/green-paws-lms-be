<?php

declare(strict_types=1);

namespace VL\LMS\Mail;

use VL\LMS\Support\AppUrlResolver;
use VL\LMS\Support\Logger;
use VL\LMS\Support\PlainText;
use WP_Post;
use WP_User;

/**
 * Phase 7.6 — cohort-session reminder email (24 h or 1 h before
 * `_vl_session_scheduled_start`).
 *
 * Concrete (not final) for DI / testability. Subject and body pass through
 * `vl_lms_session_reminder_subject` / `vl_lms_session_reminder_body`
 * filters so translation / branding overrides drop in without touching
 * this class.
 *
 * @author Tymofii Synianskyi
 */
class SessionReminderMailer {

	public function __construct(
		private readonly Logger $logger,
		private readonly AppUrlResolver $url_resolver,
		private readonly HtmlMailSender $sender
	) {
	}

	/**
	 * @param '24h'|'1h' $variant
	 */
	public function send( int $session_id, int $user_id, string $variant ): bool {
		$session = get_post( $session_id );
		if ( ! $session instanceof WP_Post || 'vl_session' !== $session->post_type ) {
			$this->logger->warning(
				'SessionReminderMailer: session post not found or wrong type.',
				[
					'session_id' => $session_id,
					'user_id'    => $user_id,
				]
			);
			return false;
		}

		$user = get_userdata( $user_id );
		if ( ! $user instanceof WP_User || '' === (string) $user->user_email ) {
			$this->logger->warning(
				'SessionReminderMailer: user not found or has no email.',
				[
					'user_id'    => $user_id,
					'session_id' => $session_id,
				]
			);
			return false;
		}

		$slug  = (string) $session->post_name;
		$url   = $this->url_resolver->path( '/learn/sessions/' . $slug );
		$title = PlainText::from_html( (string) get_the_title( $session ) );

		/**
		 * @param string $subject
		 * @param int    $session_id
		 * @param int    $user_id
		 * @param string $variant
		 */
		$subject = (string) apply_filters(
			'vl_lms_session_reminder_subject',
			$this->default_subject( $title, $variant ),
			$session_id,
			$user_id,
			$variant
		);

		$body = (string) apply_filters(
			'vl_lms_session_reminder_body',
			$this->default_body( $user, $title, $url, $variant ),
			$session_id,
			$user_id,
			$variant,
			$url
		);

		return $this->sender->send( (string) $user->user_email, $subject, $body );
	}

	private function default_subject( string $title, string $variant ): string {
		if ( '1h' === $variant ) {
			return sprintf(
				/* translators: %s: session title */
				__( 'Session "%s" starts in one hour', 'vl-lms' ),
				$title
			);
		}
		return sprintf(
			/* translators: %s: session title */
			__( 'Session "%s" starts tomorrow', 'vl-lms' ),
			$title
		);
	}

	private function default_body( WP_User $user, string $title, string $url, string $variant ): string {
		$greeting_name = '' !== (string) $user->first_name ? (string) $user->first_name : (string) $user->user_login;

		$timing = '1h' === $variant
			? __( 'Your cohort session starts in about one hour.', 'vl-lms' )
			: __( 'Your cohort session starts tomorrow.', 'vl-lms' );

		return sprintf(
			'<p>%s %s,</p>'
			. '<p>%s</p>'
			. '<p><strong>%s</strong></p>'
			. '<p><a href="%s">%s</a></p>',
			esc_html__( 'Hello', 'vl-lms' ),
			esc_html( $greeting_name ),
			esc_html( $timing ),
			esc_html( $title ),
			esc_url( $url ),
			esc_html__( 'Open session', 'vl-lms' )
		);
	}
}
