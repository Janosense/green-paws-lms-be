<?php

declare(strict_types=1);

namespace VL\LMS\Mail;

use VL\LMS\Support\Logger;
use WP_Post;
use WP_User;

/**
 * Phase 7.6 — webinar reminder email (24 h / 1 h before
 * `_vl_webinar_scheduled_start`). Sibling of {@see SessionReminderMailer};
 * the surfaces are similar enough to share a pattern but distinct enough
 * not to share a base class — the link target and the subject copy diverge.
 *
 * @author Tymofii Synianskyi
 */
class WebinarReminderMailer {

	public function __construct(
		private readonly Logger $logger,
		private readonly AppUrlResolver $url_resolver,
		private readonly HtmlMailSender $sender
	) {
	}

	/**
	 * @param '24h'|'1h' $variant
	 */
	public function send( int $webinar_id, int $user_id, string $variant ): bool {
		$webinar = get_post( $webinar_id );
		if ( ! $webinar instanceof WP_Post || 'vl_webinar' !== $webinar->post_type ) {
			$this->logger->warning(
				'WebinarReminderMailer: webinar post not found or wrong type.',
				[
					'webinar_id' => $webinar_id,
					'user_id'    => $user_id,
				]
			);
			return false;
		}

		$user = get_userdata( $user_id );
		if ( ! $user instanceof WP_User || '' === (string) $user->user_email ) {
			$this->logger->warning(
				'WebinarReminderMailer: user not found or has no email.',
				[
					'user_id'    => $user_id,
					'webinar_id' => $webinar_id,
				]
			);
			return false;
		}

		$slug  = (string) $webinar->post_name;
		$url   = $this->url_resolver->path( '/dashboard/webinars/' . $slug );
		$title = wp_strip_all_tags( get_the_title( $webinar ) );

		$subject = (string) apply_filters(
			'vl_lms_webinar_reminder_subject',
			$this->default_subject( $title, $variant ),
			$webinar_id,
			$user_id,
			$variant
		);

		$body = (string) apply_filters(
			'vl_lms_webinar_reminder_body',
			$this->default_body( $user, $title, $url, $variant ),
			$webinar_id,
			$user_id,
			$variant,
			$url
		);

		return $this->sender->send( (string) $user->user_email, $subject, $body );
	}

	private function default_subject( string $title, string $variant ): string {
		if ( '1h' === $variant ) {
			return sprintf(
				/* translators: %s: webinar title */
				__( 'Webinar "%s" starts in one hour', 'vl-lms' ),
				$title
			);
		}
		return sprintf(
			/* translators: %s: webinar title */
			__( 'Webinar "%s" starts tomorrow', 'vl-lms' ),
			$title
		);
	}

	private function default_body( WP_User $user, string $title, string $url, string $variant ): string {
		$greeting_name = '' !== (string) $user->first_name ? (string) $user->first_name : (string) $user->user_login;

		$timing = '1h' === $variant
			? __( 'Your webinar starts in about one hour.', 'vl-lms' )
			: __( 'Your webinar starts tomorrow.', 'vl-lms' );

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
			esc_html__( 'Open webinar', 'vl-lms' )
		);
	}
}
