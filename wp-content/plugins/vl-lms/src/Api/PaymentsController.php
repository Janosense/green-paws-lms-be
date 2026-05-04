<?php

declare(strict_types=1);

namespace VL\LMS\Api;

use VL\LMS\Payments\LiqPay\CallbackHandler;
use VL\LMS\Payments\LiqPay\CallbackParser;
use VL\LMS\Support\Logger;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Public REST receiver for the LiqPay server-to-server callback.
 *
 * Single endpoint:
 *
 *   POST /vl/v1/payments/liqpay/callback
 *
 * Auth model: signature verification IS the auth — `permission_callback`
 * is `__return_true`. The {@see CallbackParser} embedded inside calls
 * {@see \VL\LMS\Payments\LiqPay\SignatureVerifier} and refuses to return
 * a payload unless the HMAC matches.
 *
 * Response model: every outcome maps to `200 OK` with an empty body.
 * LiqPay's retry policy hammers non-2xx aggressively, so we absorb
 * malformed / forged / amount-mismatched callbacks at the HTTP layer
 * and let ops monitor warning rates instead.
 *
 * Concrete (not final). Mockery-mockable in unit tests.
 *
 * @author Tymofii Synianskyi
 */
class PaymentsController {

	public const string CALLBACK_ROUTE = '/payments/liqpay/callback';

	public function __construct(
		private readonly string $rest_namespace,
		private readonly CallbackParser $parser,
		private readonly CallbackHandler $handler,
		private readonly Logger $logger
	) {
	}

	public function register_routes(): void {
		register_rest_route(
			$this->rest_namespace,
			self::CALLBACK_ROUTE,
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'callback' ],
				'permission_callback' => '__return_true',
			]
		);
	}

	public function callback( WP_REST_Request $request ): WP_REST_Response {
		$data      = $request->get_param( 'data' );
		$signature = $request->get_param( 'signature' );

		if ( ! is_string( $data ) || '' === $data || ! is_string( $signature ) || '' === $signature ) {
			$this->logger->warning(
				'LiqPay callback received without `data` / `signature` form fields.',
				[
					'has_data'      => is_string( $data ) && '' !== $data,
					'has_signature' => is_string( $signature ) && '' !== $signature,
				]
			);
			return $this->ok();
		}

		$payload = $this->parser->parse( $data, $signature );
		if ( null === $payload ) {
			$this->logger->warning(
				'LiqPay callback failed to parse / verify',
				[ 'data_prefix' => substr( $data, 0, 64 ) ]
			);
			return $this->ok();
		}

		$outcome = $this->handler->handle( $payload );
		$this->logger->info(
			'LiqPay callback handled',
			[
				'outcome'    => $outcome->value,
				'order_id'   => $payload->order_id(),
				'status'     => $payload->status(),
				'payment_id' => $payload->payment_id(),
			]
		);

		return $this->ok();
	}

	private function ok(): WP_REST_Response {
		$response = rest_ensure_response( null );
		$response->set_status( 200 );
		return $response;
	}
}
