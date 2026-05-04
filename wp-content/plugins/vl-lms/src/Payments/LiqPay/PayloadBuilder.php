<?php

declare(strict_types=1);

namespace VL\LMS\Payments\LiqPay;

use VL\LMS\Domain\Order\Order;
use VL\LMS\Domain\Order\PurchasableEntityType;
use VL\LMS\Support\AppUrlResolver;

/**
 * Builds the LiqPay v3 `data` payload for a {@see Order}.
 *
 * Output is the associative payload — the caller (LiqPayClient) is
 * responsible for JSON-encoding, base64-wrapping, and signing.
 *
 * Description copy is Ukrainian (`Курс: ...` / `Вебінар: ...`); the
 * entity title snapshot is truncated to 200 characters before assembly so
 * the final string fits LiqPay's 255-char `description` limit.
 *
 * @author Tymofii Synianskyi
 */
class PayloadBuilder {

	private const int TITLE_MAX_LENGTH = 200;

	public function __construct(
		private readonly Settings $settings,
		private readonly AppUrlResolver $app_url_resolver
	) {
	}

	/**
	 * @return array<string, scalar>
	 */
	public function build( Order $order, string $server_url ): array {
		return [
			'version'     => 3,
			'public_key'  => $this->settings->public_key(),
			'action'      => 'pay',
			'amount'      => $order->amount->to_major_decimal(),
			'currency'    => $order->amount->currency(),
			'description' => $this->build_description( $order ),
			'order_id'    => $order->uuid,
			'result_url'  => $this->app_url_resolver->path( '/orders/' . $order->uuid . '/result' ),
			'server_url'  => $server_url,
			'sandbox'     => $this->settings->is_sandbox() ? 1 : 0,
			'language'    => 'uk',
		];
	}

	private function build_description( Order $order ): string {
		$prefix = match ( $order->entity_type ) {
			PurchasableEntityType::COURSE  => 'Курс',
			PurchasableEntityType::WEBINAR => 'Вебінар',
		};
		$title = $order->entity_title_snapshot;
		if ( mb_strlen( $title ) > self::TITLE_MAX_LENGTH ) {
			$title = mb_substr( $title, 0, self::TITLE_MAX_LENGTH );
		}
		return $prefix . ': ' . $title;
	}
}
