<?php

declare(strict_types=1);

namespace VL\LMS\Orders;

use VL\LMS\Domain\Enrollment\EnrollmentSource;
use VL\LMS\Domain\Order\Order;
use VL\LMS\Domain\Order\PurchasableEntityType;
use VL\LMS\Domain\Payment\Payment;
use VL\LMS\Services\Enrollment\EnrollmentService;
use VL\LMS\Services\Webinars\WebinarRegistrationService;
use VL\LMS\Support\Logger;

/**
 * Phase 8.2 — listens to {@see vl_lms_order_paid} and provisions the
 * underlying enrollment / webinar registration.
 *
 * Switches on `Order::$entity_type`:
 *  - `COURSE`  → `EnrollmentService::enroll(... source=PURCHASE, source_order_id=$order->id)`
 *  - `WEBINAR` → `WebinarRegistrationService::register_for_purchase(...)`
 *
 * Both downstream calls are idempotent at the service level, so no in-listener
 * dedup is required — re-firing the action with the same order is a no-op.
 *
 * Subscribes at WP-hook priority 10 (provisioning is the most fundamental
 * side-effect of payment). Future listeners on the same action — Phase 8.5
 * mailers, etc. — should subscribe at priority ≥ 20 so the enrollment /
 * registration is already in place when they observe the action.
 *
 * Exception policy mirrors {@see \VL\LMS\Certificate\CertificateRevoker}:
 * service-level exceptions bubble up, letting WP's action infrastructure
 * surface the failure rather than swallowing into a silent miss.
 *
 * Concrete (not final) for DI / testability.
 *
 * @author Tymofii Synianskyi
 */
class OrderEnrollmentFanout {

	public function __construct(
		private readonly EnrollmentService $enrollments,
		private readonly WebinarRegistrationService $webinars,
		private readonly Logger $logger
	) {
	}

	public function on_order_paid( Order $order, Payment $payment ): void {
		unset( $payment ); // Reserved for future listeners; this listener does not consume it.

		$order_id = (int) $order->id;

		match ( $order->entity_type ) {
			PurchasableEntityType::COURSE => $this->enroll_course( $order, $order_id ),
			PurchasableEntityType::WEBINAR => $this->register_webinar( $order, $order_id ),
		};

		$this->logger->info(
			'Order fan-out completed',
			[
				'order_uuid'  => $order->uuid,
				'entity_type' => $order->entity_type->value,
				'entity_id'   => $order->entity_id,
				'user_id'     => $order->user_id,
			]
		);
	}

	private function enroll_course( Order $order, int $order_id ): void {
		$this->enrollments->enroll(
			user_id: $order->user_id,
			course_id: $order->entity_id,
			source: EnrollmentSource::PURCHASE,
			source_group_id: null,
			source_order_id: $order_id
		);
	}

	private function register_webinar( Order $order, int $order_id ): void {
		$this->webinars->register_for_purchase(
			user_id: $order->user_id,
			webinar_id: $order->entity_id,
			source_order_id: $order_id
		);
	}
}
