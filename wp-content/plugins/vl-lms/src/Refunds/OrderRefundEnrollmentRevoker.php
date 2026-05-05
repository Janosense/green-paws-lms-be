<?php

declare(strict_types=1);

namespace VL\LMS\Refunds;

use VL\LMS\Domain\Enrollment\EnrollmentStatus;
use VL\LMS\Domain\Order\Order;
use VL\LMS\Domain\Order\PurchasableEntityType;
use VL\LMS\Domain\Payment\Payment;
use VL\LMS\Services\Enrollment\EnrollmentService;
use VL\LMS\Services\Webinars\WebinarRegistrationService;
use VL\LMS\Support\Logger;

/**
 * Phase 8.3 — listens to {@see vl_lms_order_refunded} and revokes the
 * underlying enrollment / webinar registration.
 *
 * Symmetric inverse of {@see \VL\LMS\Orders\OrderEnrollmentFanout}:
 *  - `COURSE`  → {@see EnrollmentService::revoke()} (which fires
 *                `vl_lms_enrollment_revoked` and chains into Phase 6.3's
 *                `CertificateRevoker` to soft-revoke any certificates).
 *  - `WEBINAR` → {@see WebinarRegistrationService::revoke_for_refund()}
 *                (the new gate-bypassing method introduced in 8.3,
 *                symmetric to 8.2's `register_for_purchase`).
 *
 * Subscribes at WP-hook priority 10 (revocation is the most fundamental
 * side-effect of refund). Future listeners on the same action — refund
 * mailers in 8.5, etc. — should subscribe at priority ≥ 20 so the access
 * removal is already in place when they observe the action.
 *
 * Exception policy mirrors {@see \VL\LMS\Orders\OrderEnrollmentFanout}:
 * service-level exceptions bubble up so WP's action infrastructure
 * surfaces the failure. The downstream services are themselves idempotent
 * for the already-revoked case, so re-firing the action is safe.
 *
 * Concrete (not final) for DI / testability.
 *
 * @author Tymofii Synianskyi
 */
class OrderRefundEnrollmentRevoker {

	/**
	 * `revoked_by = 0` denotes a system-initiated revocation. Refunds are
	 * triggered by an admin operator in REST, but the revocation of the
	 * resulting enrollment is a downstream automatic consequence — no
	 * specific user-id is meaningful at this layer.
	 */
	private const int SYSTEM_REVOKED_BY = 0;
	private const string REVOKE_REASON  = 'order_refunded';

	public function __construct(
		private readonly EnrollmentService $enrollments,
		private readonly WebinarRegistrationService $webinars,
		private readonly Logger $logger
	) {
	}

	public function on_order_refunded( Order $order, Payment $refund_payment ): void {
		unset( $refund_payment ); // Reserved for future listeners; not consumed here.

		match ( $order->entity_type ) {
			PurchasableEntityType::COURSE  => $this->revoke_course_enrollment( $order ),
			PurchasableEntityType::WEBINAR => $this->revoke_webinar_registration( $order ),
		};
	}

	private function revoke_course_enrollment( Order $order ): void {
		$enrollment = $this->enrollments->find_for_user_and_course( $order->user_id, $order->entity_id );
		if ( null === $enrollment ) {
			$this->logger->info(
				'Refund revoke: no enrollment to revoke',
				[
					'order_uuid' => $order->uuid,
					'user_id'    => $order->user_id,
					'course_id'  => $order->entity_id,
				]
			);
			return;
		}
		if ( EnrollmentStatus::ACTIVE !== $enrollment->status && EnrollmentStatus::COMPLETED !== $enrollment->status ) {
			$this->logger->info(
				'Refund revoke: enrollment already in non-active state',
				[
					'order_uuid'    => $order->uuid,
					'enrollment_id' => $enrollment->id,
					'status'        => $enrollment->status->value,
				]
			);
			return;
		}

		$this->enrollments->revoke(
			$enrollment->id,
			self::SYSTEM_REVOKED_BY,
			self::REVOKE_REASON
		);
	}

	private function revoke_webinar_registration( Order $order ): void {
		$revoked = $this->webinars->revoke_for_refund(
			user_id: $order->user_id,
			webinar_id: $order->entity_id,
			source_order_id: (int) $order->id
		);

		if ( ! $revoked ) {
			$this->logger->info(
				'Refund revoke: no active webinar registration to revoke',
				[
					'order_uuid' => $order->uuid,
					'user_id'    => $order->user_id,
					'webinar_id' => $order->entity_id,
				]
			);
		}
	}
}
