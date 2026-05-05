<?php

declare(strict_types=1);

namespace VL\LMS\Services\Webinars;

use Closure;
use VL\LMS\Domain\WebinarRegistration\WebinarRegistration;
use VL\LMS\Domain\WebinarRegistration\WebinarRegistrationSource;
use VL\LMS\Domain\WebinarRegistration\WebinarRegistrationStatus;
use VL\LMS\Repositories\WebinarRegistrationRepository;
use WP_Post;

/**
 * Domain service for the webinar registration lifecycle.
 *
 * Runs the validation pipeline (slug lookup → publish gate → registration
 * window → free-tier price gate → capacity counter), upserts via the
 * repository, and returns a deterministic {@see WebinarRegistrationDecision}
 * — never throws across the boundary, so the REST controller can map the
 * decision tree to HTTP without try/catch noise.
 *
 * Concrete (not final) for DI / testability.
 *
 * @author Tymofii Synianskyi
 */
class WebinarRegistrationService {

	/** @var Closure(): \DateTimeImmutable */
	private readonly Closure $clock;

	/**
	 * @param Closure(): \DateTimeImmutable $clock
	 */
	public function __construct(
		private readonly WebinarLookup $lookup,
		private readonly WebinarRegistrationRepository $registrations,
		Closure $clock
	) {
		$this->clock = $clock;
	}

	public function register(
		int $user_id,
		string $slug,
		WebinarRegistrationSource $source
	): WebinarRegistrationDecision {
		$webinar = $this->lookup->find_by_slug( $slug );
		if ( ! $webinar instanceof WP_Post ) {
			return WebinarRegistrationDecision::failure( WebinarRegistrationError::WEBINAR_NOT_FOUND );
		}
		// Defensive — `WebinarLookup` already filters on `publish`. Kept so
		// a future relaxation of the lookup doesn't silently allow draft
		// registrations.
		if ( 'publish' !== $webinar->post_status ) {
			return WebinarRegistrationDecision::failure( WebinarRegistrationError::NOT_PUBLISHED );
		}

		$now = ( $this->clock )();

		$opens_at_raw = (string) get_post_meta( (int) $webinar->ID, '_vl_webinar_registration_opens_at', true );
		if ( '' !== $opens_at_raw ) {
			$opens_at = $this->parse_iso8601( $opens_at_raw );
			if ( null !== $opens_at && $now < $opens_at ) {
				return WebinarRegistrationDecision::failure(
					WebinarRegistrationError::REGISTRATION_NOT_OPEN_YET,
					[ 'opens_at' => $opens_at_raw ]
				);
			}
		}

		$closes_at_raw = (string) get_post_meta( (int) $webinar->ID, '_vl_webinar_registration_closes_at', true );
		if ( '' !== $closes_at_raw ) {
			$closes_at = $this->parse_iso8601( $closes_at_raw );
			if ( null !== $closes_at && $now > $closes_at ) {
				return WebinarRegistrationDecision::failure(
					WebinarRegistrationError::REGISTRATION_CLOSED,
					[ 'closes_at' => $closes_at_raw ]
				);
			}
		}

		$price = (float) get_post_meta( (int) $webinar->ID, '_vl_webinar_price', true );
		if ( $price > 0.0 ) {
			$currency = (string) get_post_meta( (int) $webinar->ID, '_vl_webinar_currency', true );
			if ( '' === $currency ) {
				$currency = 'UAH';
			}
			return WebinarRegistrationDecision::failure(
				WebinarRegistrationError::PAYMENT_REQUIRED,
				[
					'price' => [
						'amount'   => $price,
						'currency' => $currency,
					],
				]
			);
		}

		$capacity = (int) get_post_meta( (int) $webinar->ID, '_vl_webinar_max_attendees', true );
		if ( $capacity > 0 ) {
			$active_count = $this->registrations->count_active_for_webinar( (int) $webinar->ID );
			$existing     = $this->registrations->find( (int) $webinar->ID, $user_id );
			$is_active    = null !== $existing && WebinarRegistrationStatus::ACTIVE === $existing->status;
			if ( ! $is_active && $active_count >= $capacity ) {
				return WebinarRegistrationDecision::failure(
					WebinarRegistrationError::CAPACITY_REACHED,
					[ 'capacity' => $capacity ]
				);
			}
		}

		$prior = $this->registrations->find( (int) $webinar->ID, $user_id );
		if ( null !== $prior && WebinarRegistrationStatus::ACTIVE === $prior->status ) {
			return WebinarRegistrationDecision::success(
				WebinarRegistrationDecisionType::ALREADY_ACTIVE,
				$prior
			);
		}

		$persisted = $this->registrations->register( (int) $webinar->ID, $user_id, $source, $now );
		$decision  = null === $prior
			? WebinarRegistrationDecisionType::REGISTERED
			: WebinarRegistrationDecisionType::RE_REGISTERED;

		return WebinarRegistrationDecision::success( $decision, $persisted );
	}

	public function cancel( int $user_id, string $slug ): WebinarRegistrationDecision {
		$webinar = $this->lookup->find_by_slug( $slug );
		if ( ! $webinar instanceof WP_Post ) {
			return WebinarRegistrationDecision::failure( WebinarRegistrationError::WEBINAR_NOT_FOUND );
		}

		$existing = $this->registrations->find( (int) $webinar->ID, $user_id );
		if ( null === $existing ) {
			return WebinarRegistrationDecision::failure( WebinarRegistrationError::NOT_REGISTERED );
		}
		if ( WebinarRegistrationStatus::CANCELLED === $existing->status ) {
			return WebinarRegistrationDecision::success(
				WebinarRegistrationDecisionType::ALREADY_CANCELLED,
				$existing
			);
		}

		$now       = ( $this->clock )();
		$cancelled = $this->registrations->cancel( (int) $webinar->ID, $user_id, $now );
		if ( null === $cancelled ) {
			return WebinarRegistrationDecision::failure( WebinarRegistrationError::NOT_REGISTERED );
		}
		return WebinarRegistrationDecision::success(
			WebinarRegistrationDecisionType::CANCELLED,
			$cancelled
		);
	}

	private function parse_iso8601( string $value ): ?\DateTimeImmutable {
		try {
			return new \DateTimeImmutable( $value );
		} catch ( \Throwable ) {
			return null;
		}
	}

	/**
	 * @return WebinarRegistration|null
	 */
	public function find_active( int $webinar_id, int $user_id ): ?WebinarRegistration {
		return $this->registrations->find_active( $webinar_id, $user_id );
	}

	/**
	 * Phase 8.1 — used by `OrderService::create_for_purchase` to short-circuit
	 * a webinar purchase when the learner already holds an active registration.
	 */
	public function has_active_registration( int $user_id, int $webinar_id ): bool {
		return null !== $this->registrations->find_active( $webinar_id, $user_id );
	}

	/**
	 * Phase 8.2 — provisioning entry-point for the post-payment fan-out.
	 *
	 * Bypasses every gate the slug-based {@see register} runs (publish state,
	 * registration window, free-tier price gate, capacity counter): payment
	 * was already received, so a webinar that has since gone over capacity or
	 * out of its registration window must still be honoured here. Refund-on-
	 * race lands in Phase 8.3. Idempotent — a second call for the same
	 * `(user_id, webinar_id)` pair returns the existing active registration
	 * rather than re-INSERTing.
	 *
	 * `$source_order_id` is accepted for the audit-trail symmetry with
	 * `EnrollmentService::enroll`. The `vl_webinar_registrations` schema has
	 * no `source_order_id` column (Phase 7.3 / 8.0 — audit trail lives on
	 * `vl_orders`), so the parameter is currently a forward-compatibility
	 * placeholder; storage-side it goes nowhere.
	 */
	public function register_for_purchase(
		int $user_id,
		int $webinar_id,
		int $source_order_id
	): WebinarRegistration {
		unset( $source_order_id ); // Reserved for the audit trail; stored on `vl_orders`.

		$existing = $this->registrations->find_active( $webinar_id, $user_id );
		if ( null !== $existing ) {
			return $existing;
		}
		$now = ( $this->clock )();
		return $this->registrations->register(
			$webinar_id,
			$user_id,
			WebinarRegistrationSource::PURCHASE,
			$now
		);
	}

	/**
	 * Phase 8.3 — gate-bypassing revocation entry-point for the order-refund
	 * fan-out. Symmetric inverse of {@see register_for_purchase}.
	 *
	 * Bypasses every gate the user-driven {@see cancel} runs (timing /
	 * lifecycle rules, etc.): payment was reversed, so the registration must
	 * be terminated regardless of whether the webinar already started, has
	 * already been attended, or sits inside a "no-cancellation" window.
	 *
	 * Idempotent — if no active registration exists, returns `false`. The
	 * `$source_order_id` parameter is currently accepted but not stored
	 * (per Phase 8.0's locked decision: no `source_order_id` column on
	 * `vl_webinar_registrations`); reserved for forward-compatibility and
	 * for inclusion in future event payloads.
	 *
	 * @return bool true if a registration was revoked, false on no-op.
	 */
	public function revoke_for_refund(
		int $user_id,
		int $webinar_id,
		int $source_order_id
	): bool {
		unset( $source_order_id ); // Reserved for the audit trail; stored on `vl_orders`.

		$existing = $this->registrations->find( $webinar_id, $user_id );
		if ( null === $existing ) {
			return false;
		}
		if ( WebinarRegistrationStatus::ACTIVE !== $existing->status ) {
			return false;
		}

		$now       = ( $this->clock )();
		$cancelled = $this->registrations->cancel( $webinar_id, $user_id, $now );
		return null !== $cancelled;
	}

	/**
	 * Phase 8.1 — capacity pre-validation for `OrderService::create_for_purchase`.
	 *
	 * Reads `_vl_webinar_max_attendees` (matching the meta key used by the
	 * registration pipeline above). Zero / missing capacity means unlimited.
	 * The residual TOCTOU window between this check and the post-payment
	 * registration in 8.2 is handled by 8.3's refund flow if it ever fires.
	 */
	public function has_capacity( int $webinar_id ): bool {
		$capacity = (int) get_post_meta( $webinar_id, '_vl_webinar_max_attendees', true );
		if ( $capacity <= 0 ) {
			return true;
		}
		return $this->registrations->count_active_for_webinar( $webinar_id ) < $capacity;
	}
}
