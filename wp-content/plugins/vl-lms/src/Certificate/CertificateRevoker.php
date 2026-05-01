<?php

declare(strict_types=1);

namespace VL\LMS\Certificate;

use VL\LMS\Repositories\CertificateRepository;
use VL\LMS\Support\Logger;

/**
 * Listens for `vl_lms_enrollment_revoked` and soft-revokes any
 * certificates issued for that enrollment.
 *
 * Soft-revoke is L1 (carry-over): the row's `revoked_at` is populated,
 * but the cached PDF on disk is intentionally NOT deleted. The download
 * endpoint and the public verification surface return 410 / "revoked"
 * respectively, but anyone who already has the binary URL can still
 * retrieve the file. This is by design — soft revocation prioritises
 * audit trail over hard takedown.
 *
 * Failures during revocation are logged and swallowed: a botched
 * certificate write must not unwind the enrollment-revoke transaction.
 *
 * @author Tymofii Synianskyi
 */
class CertificateRevoker {

	public function __construct(
		private readonly CertificateService $service,
		private readonly CertificateRepository $certificates,
		private readonly Logger $logger
	) {
	}

	/**
	 * Hooks `vl_lms_enrollment_revoked` at priority 20.
	 *
	 * Priority 20 is intentional: domain listeners that need to fire
	 * before certificates (e.g. a notifications subsystem in a future
	 * phase) get the default priority 10 slot.
	 */
	public function register(): void {
		add_action(
			'vl_lms_enrollment_revoked',
			[ $this, 'on_enrollment_revoked' ],
			20,
			2
		);
	}

	/**
	 * Action callback. Strips the `$reason` argument because revocation
	 * does not surface that string anywhere user-visible — it's only an
	 * audit field on the enrollment row.
	 */
	public function on_enrollment_revoked( int $enrollment_id, ?string $reason = null ): void {
		unset( $reason );
		$this->revoke_for_enrollment( $enrollment_id );
	}

	/**
	 * Soft-revoke every certificate row pointing at the given
	 * enrollment. Returns the count of rows actually flipped (i.e.
	 * those that were not already revoked).
	 */
	public function revoke_for_enrollment( int $enrollment_id, ?\DateTimeImmutable $when = null ): int {
		$certificates = $this->certificates->list_for_enrollment( $enrollment_id );
		if ( [] === $certificates ) {
			return 0;
		}

		$flipped   = 0;
		$timestamp = $when ?? new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );

		foreach ( $certificates as $cert ) {
			if ( null !== $cert->revoked_at ) {
				continue;
			}
			try {
				if ( $this->service->revoke( $cert->id, $timestamp ) ) {
					++$flipped;
					$this->logger->info(
						'CertificateRevoker: certificate revoked',
						[
							'uuid'          => $cert->uuid,
							'enrollment_id' => $enrollment_id,
						]
					);
				}
			} catch ( \Throwable $e ) {
				$this->logger->error(
					'CertificateRevoker: failed to revoke certificate',
					[
						'uuid'          => $cert->uuid,
						'enrollment_id' => $enrollment_id,
						'error'         => $e->getMessage(),
					]
				);
			}
		}

		return $flipped;
	}
}
