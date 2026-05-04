<?php

declare(strict_types=1);

namespace VL\LMS\Services\Notifications;

use VL\LMS\Mail\CertificateIssuedMailer;
use VL\LMS\Support\Logger;

/**
 * Phase 7.6 — listens to the new `vl_lms_certificate_issued` action fired
 * by {@see \VL\LMS\Certificate\CertificateAutoIssuer} on the new-issue
 * branch and dispatches the certificate-issued email.
 *
 * @author Tymofii Synianskyi
 */
class CertificateIssuedListener {

	public function __construct(
		private readonly CertificateIssuedMailer $mailer,
		private readonly Logger $logger
	) {
	}

	public function register(): void {
		add_action( 'vl_lms_certificate_issued', [ $this, 'on_certificate_issued' ], 10, 2 );
	}

	public function on_certificate_issued( int $certificate_id, int $user_id ): void {
		$ok = $this->mailer->send( $certificate_id, $user_id );
		$this->logger->info(
			'CertificateIssuedListener: dispatched certificate-issued email.',
			[
				'certificate_id' => $certificate_id,
				'user_id'        => $user_id,
				'sent'           => $ok,
			]
		);
	}
}
