<?php

declare(strict_types=1);

namespace VL\LMS\Mail;

use VL\LMS\Repositories\CertificateRepository;
use VL\LMS\Support\AppUrlResolver;
use VL\LMS\Support\Logger;
use WP_User;

/**
 * Phase 7.6 — certificate-issued email (Phase 6 carry-over). Triggered by
 * `Services\Notifications\CertificateIssuedListener` on the new
 * `vl_lms_certificate_issued` action that `CertificateAutoIssuer` fires
 * after a successful new-issue (NOT idempotent / skipped / failed).
 *
 * @author Tymofii Synianskyi
 */
class CertificateIssuedMailer {

	public function __construct(
		private readonly Logger $logger,
		private readonly CertificateRepository $certificates,
		private readonly AppUrlResolver $url_resolver,
		private readonly HtmlMailSender $sender
	) {
	}

	public function send( int $certificate_id, int $user_id ): bool {
		$certificate = $this->certificates->find( $certificate_id );
		if ( null === $certificate ) {
			$this->logger->warning(
				'CertificateIssuedMailer: certificate not found.',
				[
					'certificate_id' => $certificate_id,
					'user_id'        => $user_id,
				]
			);
			return false;
		}

		$user = get_userdata( $user_id );
		if ( ! $user instanceof WP_User || '' === (string) $user->user_email ) {
			$this->logger->warning(
				'CertificateIssuedMailer: user not found or has no email.',
				[
					'user_id'        => $user_id,
					'certificate_id' => $certificate_id,
				]
			);
			return false;
		}

		$snapshot     = $certificate->snapshot_data;
		$course_title = isset( $snapshot['course']['title'] ) && is_string( $snapshot['course']['title'] )
			? (string) $snapshot['course']['title']
			: __( 'your course', 'vl-lms' );

		$url = $this->url_resolver->path( '/dashboard/certificates/' . $certificate->uuid );

		$subject = (string) apply_filters(
			'vl_lms_certificate_issued_subject',
			sprintf(
				/* translators: %s: course title */
				__( 'Certificate issued: "%s"', 'vl-lms' ),
				$course_title
			),
			$certificate_id,
			$user_id
		);

		$body = (string) apply_filters(
			'vl_lms_certificate_issued_body',
			$this->default_body( $user, $course_title, $url ),
			$certificate_id,
			$user_id,
			$url
		);

		return $this->sender->send( (string) $user->user_email, $subject, $body );
	}

	private function default_body( WP_User $user, string $course_title, string $url ): string {
		$greeting_name = '' !== (string) $user->first_name ? (string) $user->first_name : (string) $user->user_login;

		return sprintf(
			'<p>%s %s,</p>'
			. '<p>%s</p>'
			. '<p><strong>%s</strong></p>'
			. '<p><a href="%s">%s</a></p>',
			esc_html__( 'Hello', 'vl-lms' ),
			esc_html( $greeting_name ),
			esc_html__( 'Congratulations on completing your course! Your certificate is ready.', 'vl-lms' ),
			esc_html( $course_title ),
			esc_url( $url ),
			esc_html__( 'View certificate', 'vl-lms' )
		);
	}
}
