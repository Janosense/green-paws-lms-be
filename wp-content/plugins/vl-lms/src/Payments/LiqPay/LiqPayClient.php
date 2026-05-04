<?php

declare(strict_types=1);

namespace VL\LMS\Payments\LiqPay;

use VL\LMS\Domain\Order\Order;
use VL\LMS\Domain\Payment\PreparedPayment;
use VL\LMS\Payments\Exception\PaymentProviderUnavailableException;
use VL\LMS\Payments\PaymentProvider;

/**
 * LiqPay implementation of {@see PaymentProvider}.
 *
 * Phase 8.1 implements the prepare-side only — pure local computation.
 * The browser POSTs the returned form payload directly to LiqPay; no
 * server-side HTTP is performed here. Phase 8.3's refund flow is the
 * first time this codebase will call LiqPay's HTTP API (via a separate
 * `RefundCapableProvider` sub-interface).
 *
 * The callback `server_url` always targets `wp-json/vl/v1/payments/liqpay/callback`,
 * which Phase 8.2 will wire up. The URL is constructable in 8.1 even
 * though the endpoint is not yet served.
 *
 * @author Tymofii Synianskyi
 */
class LiqPayClient implements PaymentProvider {

	public const string CHECKOUT_ACTION_URL = 'https://www.liqpay.ua/api/3/checkout';
	public const string PAYLOAD_VERSION     = '3';
	public const string CALLBACK_REST_PATH  = 'vl/v1/payments/liqpay/callback';

	public function __construct(
		private readonly Settings $settings,
		private readonly PayloadBuilder $payload_builder,
		private readonly SignatureBuilder $signature_builder
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

	/**
	 * Indirected so unit tests can subclass and override without
	 * round-tripping through `rest_url`.
	 */
	protected function callback_url(): string {
		return (string) rest_url( self::CALLBACK_REST_PATH );
	}
}
