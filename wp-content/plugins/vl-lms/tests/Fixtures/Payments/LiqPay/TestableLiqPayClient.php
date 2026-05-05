<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Fixtures\Payments\LiqPay;

use VL\LMS\Payments\LiqPay\LiqPayClient;

/**
 * Test double of {@see LiqPayClient} that exposes a settable clock seam.
 *
 * Used by `LiqPayClientTest::test_refund_payment_happy_path_*` to assert
 * the `received_at` field of the persisted Payment row deterministically.
 */
final class TestableLiqPayClient extends LiqPayClient {

	private ?\DateTimeImmutable $clock = null;

	public function set_clock( \DateTimeImmutable $now ): void {
		$this->clock = $now;
	}

	protected function now(): \DateTimeImmutable {
		return $this->clock ?? parent::now();
	}
}
