<?php

declare(strict_types=1);

namespace VL\LMS\Certificate;

use VL\LMS\Repositories\EnrollmentRepository;
use VL\LMS\Support\Logger;

/**
 * Listens for `vl_lms_course_completed` and triggers certificate
 * issuance through {@see CertificateService}.
 *
 * The action contract decouples `CompletionPropagator` from
 * `CertificateService` — neither imports the other; this class is the
 * only place that bridges the two. Useful when later phases want to
 * intercept course completion for unrelated purposes (notifications,
 * analytics) without expanding the propagator's dependency graph.
 *
 * Errors are logged and swallowed: a botched certificate issuance must
 * not unwind the enrollment-flip transaction the propagator just
 * performed.
 *
 * @author Tymofii Synianskyi
 */
class CertificateAutoIssuer {

	public function __construct(
		private readonly CertificateService $service,
		private readonly EnrollmentRepository $enrollments,
		private readonly Logger $logger
	) {
	}

	/**
	 * Hooks `vl_lms_course_completed` at default priority 10. The
	 * downstream {@see CertificateRevoker} runs at priority 20 — that's
	 * a different action though, so the priorities don't interact here.
	 */
	public function register(): void {
		add_action(
			'vl_lms_course_completed',
			[ $this, 'auto_issue' ],
			10,
			3
		);
	}

	/**
	 * Action callback. The `$enrollment_id` arrives directly from the
	 * propagator, but we deliberately do not trust it blindly — we
	 * resolve the row before delegating, so a misfired action with a
	 * stale id logs a warning instead of trying to issue against a
	 * vanished enrollment.
	 */
	public function auto_issue( int $user_id, int $course_id, int $enrollment_id ): void {
		$enrollment = $this->enrollments->find_by_id( $enrollment_id );
		if ( null === $enrollment ) {
			$this->logger->warning(
				'CertificateAutoIssuer: enrollment vanished before issue',
				[
					'user_id'       => $user_id,
					'course_id'     => $course_id,
					'enrollment_id' => $enrollment_id,
				]
			);
			return;
		}

		try {
			$result = $this->service->issue_for_enrollment( $enrollment_id );
		} catch ( \Throwable $e ) {
			$this->logger->error(
				'CertificateAutoIssuer: issue_for_enrollment threw',
				[
					'user_id'       => $user_id,
					'course_id'     => $course_id,
					'enrollment_id' => $enrollment_id,
					'error'         => $e->getMessage(),
				]
			);
			return;
		}

		if ( $result->skipped ) {
			// Course doesn't have certificates enabled — totally normal.
			return;
		}

		if ( ! $result->success ) {
			$this->logger->error(
				'CertificateAutoIssuer: issue failed',
				[
					'user_id'       => $user_id,
					'course_id'     => $course_id,
					'enrollment_id' => $enrollment_id,
					'code'          => $result->error_code,
				]
			);
			return;
		}

		if ( $result->idempotent ) {
			$this->logger->debug(
				'CertificateAutoIssuer: certificate already issued',
				[
					'user_id'   => $user_id,
					'course_id' => $course_id,
				]
			);
			return;
		}

		$this->logger->info(
			'CertificateAutoIssuer: certificate issued',
			[
				'user_id'   => $user_id,
				'course_id' => $course_id,
				'uuid'      => $result->certificate?->uuid,
			]
		);

		/**
		 * Phase 7.6 — fired on the new-issue branch only (not on idempotent
		 * / skipped / failed). `Services\Notifications\CertificateIssuedListener`
		 * picks this up to dispatch the certificate-issued email. The action
		 * is fired AFTER all logging so a listener exception cannot rewrite
		 * the issued-state log line.
		 *
		 * @param int $certificate_id
		 * @param int $user_id
		 */
		if ( null !== $result->certificate ) {
			do_action( 'vl_lms_certificate_issued', $result->certificate->id, $user_id );
		}
	}
}
