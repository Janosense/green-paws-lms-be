<?php

declare(strict_types=1);

namespace VL\LMS\Mail;

use VL\LMS\Services\Zoom\Sync\PostKind;
use VL\LMS\Support\AppUrlResolver;
use VL\LMS\Support\Logger;
use VL\LMS\Support\PlainText;
use WP_Post;
use WP_User;

/**
 * Phase 7.6 — recording-ready email. Handles both `vl_session` and
 * `vl_webinar` posts; the link target diverges by `PostKind`. Triggered
 * by `Services\Notifications\RecordingReadyListener` when the
 * `recording.completed` Zoom webhook flips `_vl_*_recording_url` from
 * empty to populated.
 *
 * @author Tymofii Synianskyi
 */
class RecordingReadyMailer {

	public function __construct(
		private readonly Logger $logger,
		private readonly AppUrlResolver $url_resolver,
		private readonly HtmlMailSender $sender
	) {
	}

	public function send( int $entity_id, int $user_id, PostKind $kind ): bool {
		$post = get_post( $entity_id );
		if ( ! $post instanceof WP_Post || $kind->value !== $post->post_type ) {
			$this->logger->warning(
				'RecordingReadyMailer: post not found or wrong type.',
				[
					'entity_id' => $entity_id,
					'user_id'   => $user_id,
					'kind'      => $kind->value,
				]
			);
			return false;
		}

		$user = get_userdata( $user_id );
		if ( ! $user instanceof WP_User || '' === (string) $user->user_email ) {
			$this->logger->warning(
				'RecordingReadyMailer: user not found or has no email.',
				[
					'user_id'   => $user_id,
					'entity_id' => $entity_id,
				]
			);
			return false;
		}

		$slug = (string) $post->post_name;
		$url  = match ( $kind ) {
			PostKind::WEBINAR => $this->url_resolver->path( '/dashboard/webinars/' . $slug ),
			PostKind::SESSION => $this->url_resolver->path( '/learn/sessions/' . $slug ),
		};
		$title = PlainText::from_html( (string) get_the_title( $post ) );

		$subject = (string) apply_filters(
			'vl_lms_recording_ready_subject',
			$this->default_subject( $title, $kind ),
			$entity_id,
			$user_id,
			$kind->value
		);

		$body = (string) apply_filters(
			'vl_lms_recording_ready_body',
			$this->default_body( $user, $title, $url, $kind ),
			$entity_id,
			$user_id,
			$kind->value,
			$url
		);

		return $this->sender->send( (string) $user->user_email, $subject, $body );
	}

	private function default_subject( string $title, PostKind $kind ): string {
		if ( PostKind::WEBINAR === $kind ) {
			return sprintf(
				/* translators: %s: webinar title */
				__( 'Webinar recording available: "%s"', 'vl-lms' ),
				$title
			);
		}
		return sprintf(
			/* translators: %s: session title */
			__( 'Session recording available: "%s"', 'vl-lms' ),
			$title
		);
	}

	private function default_body( WP_User $user, string $title, string $url, PostKind $kind ): string {
		$greeting_name = '' !== (string) $user->first_name ? (string) $user->first_name : (string) $user->user_login;

		$preamble = PostKind::WEBINAR === $kind
			? __( 'The recording for the webinar you registered for is now available.', 'vl-lms' )
			: __( 'The recording for the session in your course is now available.', 'vl-lms' );

		$cta = PostKind::WEBINAR === $kind
			? __( 'Watch recording', 'vl-lms' )
			: __( 'Watch session recording', 'vl-lms' );

		return sprintf(
			'<p>%s %s,</p>'
			. '<p>%s</p>'
			. '<p><strong>%s</strong></p>'
			. '<p><a href="%s">%s</a></p>',
			esc_html__( 'Hello', 'vl-lms' ),
			esc_html( $greeting_name ),
			esc_html( $preamble ),
			esc_html( $title ),
			esc_url( $url ),
			esc_html( $cta )
		);
	}
}
