<?php

declare(strict_types=1);

namespace VL\LMS\Orders;

use VL\LMS\Domain\Enrollment\EnrollmentStatus;
use VL\LMS\Domain\Order\Order;
use VL\LMS\Domain\Order\OrderStatus;
use VL\LMS\Domain\Order\PurchasableEntityType;
use VL\LMS\Orders\Exception\AlreadyEnrolledException;
use VL\LMS\Orders\Exception\AlreadyRegisteredException;
use VL\LMS\Orders\Exception\EntityNotFoundException;
use VL\LMS\Orders\Exception\EntityNotPurchasableException;
use VL\LMS\Orders\Exception\OrderNotCancellableException;
use VL\LMS\Orders\Exception\OrderNotFoundException;
use VL\LMS\Orders\Exception\OrderNotOwnedException;
use VL\LMS\Orders\Exception\WebinarFullException;
use VL\LMS\Payments\PaymentProvider;
use VL\LMS\Repositories\EnrollmentRepository;
use VL\LMS\Repositories\OrderRepository;
use VL\LMS\Services\Webinars\WebinarRegistrationService;

/**
 * Phase 8.1 — order-orchestration service.
 *
 * Coordinates the four moving parts of "user wants to buy this entity":
 *   1. resolve the slug to a published course / webinar (`PurchasableLookup`),
 *   2. block obvious can-already-access cases (active enrollment / registration),
 *   3. resolve a non-zero price (`PriceResolver`) and pre-validate webinar
 *      capacity to minimise pay-then-can't-register,
 *   4. INSERT a `PENDING` order, set `liqpay_order_id = uuid`, and ask the
 *      provider for the form payload.
 *
 * Order-creation is idempotent on the `(user_id, entity_type, entity_id)`
 * triple — a second call while an open order already exists returns that
 * order with a fresh `prepare_payment` instead of inserting a duplicate.
 *
 * `InvalidEntityTypeException` is raised by the REST controller (input
 * validation), not this service — the service trusts its enum-typed
 * parameter.
 *
 * Concrete (not final) for DI / testability.
 *
 * @author Tymofii Synianskyi
 */
class OrderService {

	public const int ORDER_TTL_HOURS = 24;

	public function __construct(
		private readonly OrderRepository $orders,
		private readonly PriceResolver $prices,
		private readonly PurchasableLookup $lookup,
		private readonly EnrollmentRepository $enrollments,
		private readonly WebinarRegistrationService $webinars,
		private readonly PaymentProvider $payment_provider
	) {
	}

	/**
	 * @throws EntityNotFoundException        Slug doesn't resolve to a published entity.
	 * @throws AlreadyEnrolledException       Course already has an active enrollment for this user.
	 * @throws AlreadyRegisteredException     Webinar already has an active registration for this user.
	 * @throws EntityNotPurchasableException  Entity has no price meta (or zero price).
	 * @throws WebinarFullException           Webinar at capacity.
	 * @throws \VL\LMS\Payments\Exception\PaymentProviderUnavailableException Provider unconfigured.
	 */
	public function create_for_purchase(
		int $user_id,
		PurchasableEntityType $type,
		string $slug
	): OrderCreationResult {
		$post = $this->lookup->find( $type, $slug );
		if ( null === $post ) {
			throw new EntityNotFoundException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception.
				sprintf( 'No published %s found with slug "%s".', $type->value, $slug )
			);
		}

		$entity_id = (int) $post->ID;

		if ( PurchasableEntityType::COURSE === $type ) {
			$existing_enrollment = $this->enrollments->find_for_user_and_course( $user_id, $entity_id );
			if ( null !== $existing_enrollment
				&& ( EnrollmentStatus::ACTIVE === $existing_enrollment->status
					|| EnrollmentStatus::COMPLETED === $existing_enrollment->status )
			) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception.
				throw new AlreadyEnrolledException( $existing_enrollment->id );
			}
		}

		if ( PurchasableEntityType::WEBINAR === $type ) {
			$existing_registration = $this->webinars->find_active( $entity_id, $user_id );
			if ( null !== $existing_registration ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception.
				throw new AlreadyRegisteredException( $existing_registration->id );
			}
		}

		$price = $this->prices->resolve( $entity_id, $type );
		if ( null === $price ) {
			throw new EntityNotPurchasableException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception.
				sprintf( '%s "%s" is not purchasable (no price configured).', $type->value, $slug )
			);
		}

		if ( PurchasableEntityType::WEBINAR === $type && ! $this->webinars->has_capacity( $entity_id ) ) {
			throw new WebinarFullException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception.
				sprintf( 'Webinar "%s" has reached capacity.', $slug )
			);
		}

		$existing_open = $this->orders->find_open_for_user_and_entity( $user_id, $type, $entity_id );
		if ( null !== $existing_open ) {
			$persisted = $existing_open;
			if ( null === $persisted->liqpay_order_id ) {
				$this->orders->update_provider_reference( (int) $persisted->id, $persisted->uuid );
				$reloaded = $this->orders->find_by_id( (int) $persisted->id );
				if ( null !== $reloaded ) {
					$persisted = $reloaded;
				}
			}
			$prepared = $this->payment_provider->prepare_payment( $persisted );
			return new OrderCreationResult( $persisted, $prepared );
		}

		$now        = $this->now();
		$expires_at = $now->modify( '+' . self::ORDER_TTL_HOURS . ' hours' );

		$new_order = new Order(
			id: null,
			uuid: $this->generate_uuid(),
			user_id: $user_id,
			status: OrderStatus::PENDING,
			payment_provider: 'liqpay',
			liqpay_order_id: null,
			entity_type: $type,
			entity_id: $entity_id,
			entity_slug: (string) $post->post_name,
			entity_title_snapshot: (string) $post->post_title,
			amount: $price,
			created_at: $now,
			expires_at: $expires_at
		);

		$id        = $this->orders->insert( $new_order );
		$persisted = $this->orders->find_by_id( $id );
		if ( null === $persisted ) {
			throw new \RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception.
				sprintf( 'Order %d disappeared between insert and reload.', $id )
			);
		}

		$this->orders->update_provider_reference( $id, $persisted->uuid );
		$persisted = $this->orders->find_by_id( $id );
		if ( null === $persisted ) {
			throw new \RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception.
				sprintf( 'Order %d disappeared after provider-reference update.', $id )
			);
		}

		$prepared = $this->payment_provider->prepare_payment( $persisted );

		return new OrderCreationResult( $persisted, $prepared );
	}

	/**
	 * @throws OrderNotFoundException   No order with this uuid.
	 * @throws OrderNotOwnedException   Owner mismatch (REST maps to 404 to mask existence).
	 */
	public function find_for_user( int $user_id, string $uuid ): Order {
		$order = $this->orders->find_by_uuid( $uuid );
		if ( null === $order ) {
			throw new OrderNotFoundException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception.
				sprintf( 'No order found with uuid "%s".', $uuid )
			);
		}
		if ( $order->user_id !== $user_id ) {
			throw new OrderNotOwnedException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception.
				sprintf( 'Order "%s" does not belong to user %d.', $uuid, $user_id )
			);
		}
		return $order;
	}

	/**
	 * @throws OrderNotFoundException        No order with this uuid.
	 * @throws OrderNotOwnedException        Owner mismatch.
	 * @throws OrderNotCancellableException  Order is in any terminal state other than CANCELLED.
	 */
	public function cancel( int $user_id, string $uuid ): Order {
		$order = $this->orders->find_by_uuid( $uuid );
		if ( null === $order ) {
			throw new OrderNotFoundException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception.
				sprintf( 'No order found with uuid "%s".', $uuid )
			);
		}
		if ( $order->user_id !== $user_id ) {
			throw new OrderNotOwnedException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception.
				sprintf( 'Order "%s" does not belong to user %d.', $uuid, $user_id )
			);
		}
		if ( OrderStatus::CANCELLED === $order->status ) {
			return $order;
		}
		if ( OrderStatus::PENDING !== $order->status && OrderStatus::AWAITING_PAYMENT !== $order->status ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception.
			throw new OrderNotCancellableException( $order->status );
		}

		$now = $this->now();
		$this->orders->update_status( (int) $order->id, OrderStatus::CANCELLED, $now );

		$reloaded = $this->orders->find_by_uuid( $uuid );
		if ( null === $reloaded ) {
			throw new \RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception.
				sprintf( 'Order "%s" disappeared after cancel.', $uuid )
			);
		}
		return $reloaded;
	}

	/**
	 * Thin pass-through to {@see OrderRepository::list_for_user}.
	 *
	 * Validates pagination bounds (page ≥ 1; per_page in [1, 100]).
	 *
	 * @param list<OrderStatus>|null $statuses
	 *
	 * @return array{items: list<Order>, total: int}
	 */
	public function list_for_user(
		int $user_id,
		?array $statuses,
		int $page,
		int $per_page
	): array {
		$page     = max( 1, $page );
		$per_page = max( 1, min( 100, $per_page ) );
		return $this->orders->list_for_user( $user_id, $statuses, $page, $per_page );
	}

	protected function now(): \DateTimeImmutable {
		return new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
	}

	protected function generate_uuid(): string {
		return (string) wp_generate_uuid4();
	}
}
