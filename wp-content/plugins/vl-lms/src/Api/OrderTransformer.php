<?php

declare(strict_types=1);

namespace VL\LMS\Api;

use VL\LMS\Domain\Order\Order;

/**
 * Phase 8.1 — REST projection of {@see Order}.
 *
 * Excludes fields that are either internal (`id`, `metadata`) or that
 * leak provider-internal references (`liqpay_order_id`).
 *
 * @author Tymofii Synianskyi
 */
class OrderTransformer {

	/**
	 * @return array{
	 *     uuid: string,
	 *     status: string,
	 *     payment_provider: string,
	 *     entity_type: string,
	 *     entity_id: int,
	 *     entity_slug: string,
	 *     entity_title_snapshot: string,
	 *     amount: array{major: string, minor_units: int, currency: string},
	 *     created_at: string,
	 *     expires_at: string,
	 *     paid_at: string|null,
	 *     cancelled_at: string|null,
	 *     refunded_at: string|null
	 * }
	 */
	public function transform( Order $order ): array {
		return [
			'uuid'                  => $order->uuid,
			'status'                => $order->status->value,
			'payment_provider'      => $order->payment_provider,
			'entity_type'           => $order->entity_type->value,
			'entity_id'             => $order->entity_id,
			'entity_slug'           => $order->entity_slug,
			'entity_title_snapshot' => $order->entity_title_snapshot,
			'amount'                => [
				'major'       => $order->amount->to_major_decimal(),
				'minor_units' => $order->amount->amount_minor_units(),
				'currency'    => $order->amount->currency(),
			],
			'created_at'            => $order->created_at->format( \DateTimeInterface::ATOM ),
			'expires_at'            => $order->expires_at->format( \DateTimeInterface::ATOM ),
			'paid_at'               => null === $order->paid_at ? null : $order->paid_at->format( \DateTimeInterface::ATOM ),
			'cancelled_at'          => null === $order->cancelled_at ? null : $order->cancelled_at->format( \DateTimeInterface::ATOM ),
			'refunded_at'           => null === $order->refunded_at ? null : $order->refunded_at->format( \DateTimeInterface::ATOM ),
		];
	}
}
