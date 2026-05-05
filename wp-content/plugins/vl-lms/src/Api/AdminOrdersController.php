<?php

declare(strict_types=1);

namespace VL\LMS\Api;

use VL\LMS\Auth\RestAuthenticator;
use VL\LMS\Payments\Exception\PaymentProviderHttpException;
use VL\LMS\Payments\Exception\PaymentProviderRejectedException;
use VL\LMS\Payments\Exception\PaymentProviderUnavailableException;
use VL\LMS\Refunds\Exception\OrderNotFoundForRefundException;
use VL\LMS\Refunds\Exception\OrderNotRefundableException;
use VL\LMS\Refunds\RefundService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_User;

/**
 * Phase 8.3 — admin REST controller for the refund endpoint.
 *
 *   POST /vl/v1/admin/orders/{uuid}/refund
 *
 * Cap-gated by `vl_refund_orders` (administrator-only; introduced in 8.0).
 * Sync — admin clicks refund, controller awaits LiqPay HTTP round-trip
 * (1-3s typical), returns 200 with the updated order. Full-amount only;
 * the request body is ignored.
 *
 * Maps the {@see RefundService} typed exceptions to the documented HTTP
 * codes:
 *
 *   | Exception                                  | HTTP | Code                            |
 *   | ------------------------------------------ | ---- | ------------------------------- |
 *   | OrderNotFoundForRefundException            | 404  | `order_not_found`               |
 *   | OrderNotRefundableException                | 409  | `order_not_refundable`          |
 *   | PaymentProviderUnavailableException        | 503  | `payment_provider_unavailable`  |
 *   | PaymentProviderHttpException               | 502  | `payment_provider_error`        |
 *   | PaymentProviderRejectedException           | 502  | `payment_provider_error`        |
 *
 * Concrete (not final).
 *
 * @author Tymofii Synianskyi
 */
class AdminOrdersController {

	public const string REFUND_ROUTE = '/admin/orders/(?P<uuid>[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12})/refund';

	public const string CAP_REFUND = 'vl_refund_orders';

	public function __construct(
		private readonly string $rest_namespace,
		private readonly RestAuthenticator $authenticator,
		private readonly RefundService $refunds,
		private readonly OrderTransformer $transformer
	) {
	}

	public function register_routes(): void {
		register_rest_route(
			$this->rest_namespace,
			self::REFUND_ROUTE,
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'refund' ],
				'permission_callback' => [ $this, 'permission_refund' ],
			]
		);
	}

	/**
	 * @return bool|WP_Error
	 */
	public function permission_refund( WP_REST_Request $request ) {
		$user = $this->authenticator->user_from_request( $request );
		if ( ! $user instanceof WP_User ) {
			return $this->not_logged_in();
		}
		if ( ! $user->has_cap( self::CAP_REFUND ) ) {
			return $this->forbidden();
		}
		return true;
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function refund( WP_REST_Request $request ) {
		$user = $this->authenticator->user_from_request( $request );
		if ( ! $user instanceof WP_User ) {
			return $this->not_logged_in();
		}

		$uuid = (string) $request->get_param( 'uuid' );

		try {
			$order = $this->refunds->refund_order( $uuid );
		} catch ( OrderNotFoundForRefundException ) {
			return new WP_Error(
				'order_not_found',
				__( 'Замовлення не знайдено.', 'vl-lms' ),
				[ 'status' => 404 ]
			);
		} catch ( OrderNotRefundableException $ex ) {
			return new WP_Error(
				'order_not_refundable',
				__( 'Замовлення не може бути повернуте у поточному статусі.', 'vl-lms' ),
				[
					'status'         => 409,
					'current_status' => $ex->current_status()->value,
				]
			);
		} catch ( PaymentProviderUnavailableException ) {
			return new WP_Error(
				'payment_provider_unavailable',
				__( 'Сервіс оплати тимчасово недоступний.', 'vl-lms' ),
				[ 'status' => 503 ]
			);
		} catch ( PaymentProviderHttpException ) {
			return new WP_Error(
				'payment_provider_error',
				__( 'Сервіс оплати повернув помилку.', 'vl-lms' ),
				[
					'status' => 502,
					'reason' => 'http',
				]
			);
		} catch ( PaymentProviderRejectedException $ex ) {
			$data = [
				'status' => 502,
				'reason' => 'rejected',
			];
			if ( null !== $ex->provider_err_code() ) {
				$data['provider_err_code'] = $ex->provider_err_code();
			}
			return new WP_Error(
				'payment_provider_error',
				__( 'Сервіс оплати відхилив запит на повернення.', 'vl-lms' ),
				$data
			);
		}

		return rest_ensure_response( $this->transformer->transform( $order ) );
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
}
