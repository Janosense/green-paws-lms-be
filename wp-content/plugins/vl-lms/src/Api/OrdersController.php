<?php

declare(strict_types=1);

namespace VL\LMS\Api;

use VL\LMS\Auth\RestAuthenticator;
use VL\LMS\Domain\Order\OrderStatus;
use VL\LMS\Domain\Order\PurchasableEntityType;
use VL\LMS\Orders\Exception\AlreadyEnrolledException;
use VL\LMS\Orders\Exception\AlreadyRegisteredException;
use VL\LMS\Orders\Exception\EntityNotFoundException;
use VL\LMS\Orders\Exception\EntityNotPurchasableException;
use VL\LMS\Orders\Exception\InvalidEntityTypeException;
use VL\LMS\Orders\Exception\OrderNotCancellableException;
use VL\LMS\Orders\Exception\OrderNotFoundException;
use VL\LMS\Orders\Exception\OrderNotOwnedException;
use VL\LMS\Orders\Exception\WebinarFullException;
use VL\LMS\Orders\OrderService;
use VL\LMS\Payments\Exception\PaymentProviderUnavailableException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_User;

/**
 * Phase 8.1 — REST controller for the four authed order endpoints:
 *
 *   - `POST   /vl/v1/orders`                — create-or-resume open order;
 *                                              returns the order plus a
 *                                              signed LiqPay form payload.
 *   - `GET    /vl/v1/orders/me`             — paginated list of own orders.
 *   - `GET    /vl/v1/orders/{uuid}`         — single own order.
 *   - `POST   /vl/v1/orders/{uuid}/cancel`  — cancel own pending order.
 *
 * Permission model: `vl_purchase_course` for the create endpoint;
 * `vl_view_own_orders` for the read endpoints and cancel (cancel is
 * owner-side state management, not a separate write privilege).
 *
 * Owner-mismatch on the read/cancel paths is deliberately masked as
 * `404 order_not_found` — non-owners cannot probe for the existence of
 * someone else's orders.
 *
 * Concrete (not final).
 *
 * @author Tymofii Synianskyi
 */
class OrdersController {

	public const string CREATE_ROUTE = '/orders';
	public const string LIST_ROUTE   = '/orders/me';
	public const string ITEM_ROUTE   = '/orders/(?P<uuid>[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12})';
	public const string CANCEL_ROUTE = '/orders/(?P<uuid>[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12})/cancel';

	public const string CAP_PURCHASE = 'vl_purchase_course';
	public const string CAP_VIEW     = 'vl_view_own_orders';

	private const int DEFAULT_PER_PAGE = 20;
	private const int MAX_PER_PAGE     = 100;

	public function __construct(
		private readonly string $rest_namespace,
		private readonly RestAuthenticator $authenticator,
		private readonly OrderService $orders,
		private readonly OrderTransformer $order_transformer,
		private readonly PreparedPaymentTransformer $payment_transformer
	) {
	}

	public function register_routes(): void {
		register_rest_route(
			$this->rest_namespace,
			self::CREATE_ROUTE,
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'create' ],
				'permission_callback' => [ $this, 'permission_create' ],
				'args'                => [
					'entity_type' => [
						'type'     => 'string',
						'required' => true,
						'enum'     => [ 'course', 'webinar' ],
					],
					'slug'        => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_title',
					],
				],
			]
		);

		register_rest_route(
			$this->rest_namespace,
			self::LIST_ROUTE,
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'list_mine' ],
				'permission_callback' => [ $this, 'permission_view' ],
			]
		);

		register_rest_route(
			$this->rest_namespace,
			self::ITEM_ROUTE,
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'find' ],
				'permission_callback' => [ $this, 'permission_view' ],
			]
		);

		register_rest_route(
			$this->rest_namespace,
			self::CANCEL_ROUTE,
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'cancel' ],
				'permission_callback' => [ $this, 'permission_view' ],
			]
		);
	}

	/**
	 * @return bool|WP_Error
	 */
	public function permission_create( WP_REST_Request $request ) {
		$user = $this->authenticator->user_from_request( $request );
		if ( ! $user instanceof WP_User ) {
			return $this->not_logged_in();
		}
		if ( ! $user->has_cap( self::CAP_PURCHASE ) ) {
			return $this->forbidden();
		}
		return true;
	}

	/**
	 * @return bool|WP_Error
	 */
	public function permission_view( WP_REST_Request $request ) {
		$user = $this->authenticator->user_from_request( $request );
		if ( ! $user instanceof WP_User ) {
			return $this->not_logged_in();
		}
		if ( ! $user->has_cap( self::CAP_VIEW ) ) {
			return $this->forbidden();
		}
		return true;
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function create( WP_REST_Request $request ) {
		$user = $this->authenticator->user_from_request( $request );
		if ( ! $user instanceof WP_User ) {
			return $this->not_logged_in();
		}
		$user_id = (int) $user->ID;

		$entity_type_raw = (string) $request->get_param( 'entity_type' );
		$slug            = (string) $request->get_param( 'slug' );

		try {
			$entity_type = $this->parse_entity_type( $entity_type_raw );
			$result      = $this->orders->create_for_purchase( $user_id, $entity_type, $slug );
		} catch ( InvalidEntityTypeException ) {
			return new WP_Error(
				'invalid_entity_type',
				__( 'Невідомий тип сутності для покупки.', 'vl-lms' ),
				[ 'status' => 400 ]
			);
		} catch ( EntityNotFoundException ) {
			return new WP_Error(
				'entity_not_found',
				__( 'Курс або вебінар не знайдено.', 'vl-lms' ),
				[ 'status' => 404 ]
			);
		} catch ( EntityNotPurchasableException ) {
			return new WP_Error(
				'entity_not_purchasable',
				__( 'Цю сутність неможливо придбати.', 'vl-lms' ),
				[ 'status' => 409 ]
			);
		} catch ( AlreadyEnrolledException $e ) {
			return new WP_Error(
				'already_enrolled',
				__( 'Ви вже зареєстровані на цей курс.', 'vl-lms' ),
				[
					'status'        => 409,
					'enrollment_id' => $e->existing_enrollment_id(),
				]
			);
		} catch ( AlreadyRegisteredException $e ) {
			return new WP_Error(
				'already_registered',
				__( 'Ви вже зареєстровані на цей вебінар.', 'vl-lms' ),
				[
					'status'          => 409,
					'registration_id' => $e->existing_registration_id(),
				]
			);
		} catch ( WebinarFullException ) {
			return new WP_Error(
				'webinar_full',
				__( 'Вебінар повністю заповнений.', 'vl-lms' ),
				[ 'status' => 409 ]
			);
		} catch ( PaymentProviderUnavailableException ) {
			return new WP_Error(
				'payment_provider_unavailable',
				__( 'Сервіс оплати тимчасово недоступний.', 'vl-lms' ),
				[ 'status' => 503 ]
			);
		}

		$response = rest_ensure_response(
			[
				'order'        => $this->order_transformer->transform( $result->order ),
				'payment_form' => $this->payment_transformer->transform( $result->prepared_payment ),
			]
		);
		$response->set_status( 201 );
		return $response;
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_mine( WP_REST_Request $request ) {
		$user = $this->authenticator->user_from_request( $request );
		if ( ! $user instanceof WP_User ) {
			return $this->not_logged_in();
		}
		$user_id = (int) $user->ID;

		$status_param = (string) $request->get_param( 'status' );
		try {
			$statuses = $this->parse_statuses( $status_param );
		} catch ( \InvalidArgumentException ) {
			return new WP_Error(
				'invalid_status',
				__( 'Один або декілька переданих статусів недопустимі.', 'vl-lms' ),
				[ 'status' => 400 ]
			);
		}

		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = (int) $request->get_param( 'per_page' );
		$per_page = $per_page > 0 ? min( self::MAX_PER_PAGE, $per_page ) : self::DEFAULT_PER_PAGE;

		$result = $this->orders->list_for_user( $user_id, $statuses, $page, $per_page );

		$items = array_map(
			fn ( $order ) => $this->order_transformer->transform( $order ),
			$result['items']
		);

		return rest_ensure_response(
			[
				'items'    => $items,
				'total'    => $result['total'],
				'page'     => $page,
				'per_page' => $per_page,
			]
		);
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function find( WP_REST_Request $request ) {
		$user = $this->authenticator->user_from_request( $request );
		if ( ! $user instanceof WP_User ) {
			return $this->not_logged_in();
		}
		$user_id = (int) $user->ID;
		$uuid    = (string) $request->get_param( 'uuid' );

		try {
			$order = $this->orders->find_for_user( $user_id, $uuid );
		} catch ( OrderNotFoundException | OrderNotOwnedException ) {
			return $this->order_not_found();
		}

		return rest_ensure_response( $this->order_transformer->transform( $order ) );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function cancel( WP_REST_Request $request ) {
		$user = $this->authenticator->user_from_request( $request );
		if ( ! $user instanceof WP_User ) {
			return $this->not_logged_in();
		}
		$user_id = (int) $user->ID;
		$uuid    = (string) $request->get_param( 'uuid' );

		try {
			$order = $this->orders->cancel( $user_id, $uuid );
		} catch ( OrderNotFoundException | OrderNotOwnedException ) {
			return $this->order_not_found();
		} catch ( OrderNotCancellableException $e ) {
			return new WP_Error(
				'order_not_cancellable',
				__( 'Цей запис уже досяг кінцевого стану і не може бути скасований.', 'vl-lms' ),
				[
					'status'         => 409,
					'current_status' => $e->current_status()->value,
				]
			);
		}

		return rest_ensure_response( $this->order_transformer->transform( $order ) );
	}

	/**
	 * @throws InvalidEntityTypeException When the value is not a recognised case.
	 */
	private function parse_entity_type( string $value ): PurchasableEntityType {
		$case = PurchasableEntityType::tryFrom( $value );
		if ( null === $case ) {
			throw new InvalidEntityTypeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception.
				sprintf( 'Unknown entity_type "%s".', $value )
			);
		}
		return $case;
	}

	/**
	 * @return list<OrderStatus>|null
	 *
	 * @throws \InvalidArgumentException When any token is not a recognised status.
	 */
	private function parse_statuses( string $raw ): ?array {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return null;
		}
		$tokens = array_filter( array_map( 'trim', explode( ',', $raw ) ), static fn ( string $t ): bool => '' !== $t );
		$out    = [];
		foreach ( $tokens as $token ) {
			$case = OrderStatus::tryFrom( $token );
			if ( null === $case ) {
				throw new \InvalidArgumentException(
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception.
					sprintf( 'Unknown order status "%s".', $token )
				);
			}
			$out[] = $case;
		}
		return $out;
	}

	private function not_logged_in(): WP_Error {
		return new WP_Error(
			'rest_not_logged_in',
			__( 'You are not currently logged in.', 'vl-lms' ),
			[ 'status' => 401 ]
		);
	}

	private function forbidden(): WP_Error {
		return new WP_Error(
			'rest_forbidden',
			__( 'You do not have permission to perform this action.', 'vl-lms' ),
			[ 'status' => 403 ]
		);
	}

	private function order_not_found(): WP_Error {
		return new WP_Error(
			'order_not_found',
			__( 'Замовлення не знайдено.', 'vl-lms' ),
			[ 'status' => 404 ]
		);
	}
}
