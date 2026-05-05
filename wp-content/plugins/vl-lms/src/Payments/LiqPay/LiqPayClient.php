<?php

declare(strict_types=1);

namespace VL\LMS\Payments\LiqPay;

use VL\LMS\Domain\Order\Order;
use VL\LMS\Domain\Payment\Payment;
use VL\LMS\Domain\Payment\PaymentProvider as DomainPaymentProvider;
use VL\LMS\Domain\Payment\PaymentStatus;
use VL\LMS\Domain\Payment\PaymentTransactionType;
use VL\LMS\Domain\Payment\PreparedPayment;
use VL\LMS\Payments\Exception\PaymentProviderHttpException;
use VL\LMS\Payments\Exception\PaymentProviderRejectedException;
use VL\LMS\Payments\Exception\PaymentProviderUnavailableException;
use VL\LMS\Payments\PaymentProvider;
use VL\LMS\Payments\RefundCapableProvider;

/**
 * LiqPay implementation of {@see PaymentProvider} and {@see RefundCapableProvider}.
 *
 * Phase 8.1 wired the prepare-side; Phase 8.3 adds the refund-side. The
 * prepare path is pure local computation — the browser POSTs the returned
 * form payload directly to LiqPay. The refund path is the first outbound
 * server-to-server HTTP call in the payments subsystem; it goes through
 * {@see HttpClient} and surfaces typed exceptions for transport failures
 * vs provider rejections.
 *
 * The callback `server_url` always targets `wp-json/vl/v1/payments/liqpay/callback`
 * (Phase 8.2). Refund responses come back synchronously over the HTTP
 * round-trip; the `reversed` callback that LiqPay also fires is treated as
 * a confirmation by Phase 8.3's {@see CallbackHandler}.
 *
 * @author Tymofii Synianskyi
 */
class LiqPayClient implements PaymentProvider, RefundCapableProvider {

	public const string CHECKOUT_ACTION_URL = 'https://www.liqpay.ua/api/3/checkout';
	public const string PAYLOAD_VERSION     = '3';
	public const string CALLBACK_REST_PATH  = 'vl/v1/payments/liqpay/callback';

	public function __construct(
		private readonly Settings $settings,
		private readonly PayloadBuilder $payload_builder,
		private readonly SignatureBuilder $signature_builder,
		private readonly HttpClient $http_client,
		private readonly RefundResponseParser $refund_parser
	) {
	}

	public function prepare_payment( Order $order ): PreparedPayment {
		if ( ! $this->settings->is_configured() ) {
			throw new PaymentProviderUnavailableException(
				'LiqPay is not configured: public_key and/or private_key are missing.'
			);
		}

		$server_url = $this->callback_url();

		$payload      = $this->payload_builder->build( $order, $server_url );
		$encoded_data = base64_encode( (string) wp_json_encode( $payload ) );
		$signature    = $this->signature_builder->build( $this->settings->private_key(), $encoded_data );

		return new PreparedPayment(
			action_url: self::CHECKOUT_ACTION_URL,
			http_method: 'POST',
			fields: [
				'data'      => $encoded_data,
				'signature' => $signature,
				'version'   => self::PAYLOAD_VERSION,
			]
		);
	}

	public function refund_payment( Order $order ): Payment {
		if ( ! $this->settings->is_configured() ) {
			throw new PaymentProviderUnavailableException(
				'LiqPay is not configured: public_key and/or private_key are missing.'
			);
		}

		$payload      = $this->payload_builder->build_refund( $order );
		$encoded_data = base64_encode( (string) wp_json_encode( $payload ) );
		$signature    = $this->signature_builder->build( $this->settings->private_key(), $encoded_data );

		$envelope        = $this->http_client->post( $encoded_data, $signature );
		$refund_response = $this->refund_parser->parse( $envelope['body'] );

		if ( $refund_response->is_reversed() ) {
			return $this->build_refund_payment_row( $order, $refund_response );
		}

		if ( $refund_response->is_rejected() ) {
			$message = sprintf(
				'LiqPay refund rejected: status=%s, err_code=%s',
				$refund_response->status,
				$refund_response->err_code ?? 'n/a'
			);
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception.
			throw new PaymentProviderRejectedException( $message, $refund_response->status, $refund_response->err_code );
		}

		$message = sprintf( 'LiqPay refund response carried unexpected status "%s"', $refund_response->status );
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception.
		throw new PaymentProviderRejectedException( $message, $refund_response->status, $refund_response->err_code );
	}

	private function build_refund_payment_row( Order $order, RefundResponse $response ): Payment {
		$payment_id = $response->payment_id;
		if ( null === $payment_id ) {
			throw new PaymentProviderHttpException(
				'LiqPay refund response missing payment_id despite reversed status'
			);
		}
		$raw_json = wp_json_encode( $response->raw );
		if ( ! is_string( $raw_json ) ) {
			$raw_json = '';
		}

		return new Payment(
			id: null,
			order_id: (int) $order->id,
			provider: DomainPaymentProvider::LIQPAY,
			provider_payment_id: $payment_id,
			provider_action: 'refund',
			status: PaymentStatus::REVERSED,
			raw_provider_status: $response->status,
			transaction_type: PaymentTransactionType::REFUND,
			amount: $order->amount,
			raw_payload: $raw_json,
			received_at: $this->now(),
			idempotency_key: sprintf( 'liqpay:%s:refund:reversed', $payment_id )
		);
	}

	/**
	 * Indirected so unit tests can subclass and override without
	 * round-tripping through `rest_url`.
	 */
	protected function callback_url(): string {
		return (string) rest_url( self::CALLBACK_REST_PATH );
	}

	/**
	 * Indirected so unit tests can subclass and override the clock.
	 */
	protected function now(): \DateTimeImmutable {
		return new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
	}
}
