<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Api;

use PHPUnit\Framework\TestCase;
use VL\LMS\Api\PreparedPaymentTransformer;
use VL\LMS\Domain\Payment\PreparedPayment;

final class PreparedPaymentTransformerTest extends TestCase {

	public function test_round_trips_all_four_keys(): void {
		$prepared = new PreparedPayment(
			'https://www.liqpay.ua/api/3/checkout',
			'POST',
			[
				'data'      => 'b64',
				'signature' => 'sig',
				'version'   => '3',
			]
		);

		$result = ( new PreparedPaymentTransformer() )->transform( $prepared );

		self::assertSame(
			[
				'action_url'  => 'https://www.liqpay.ua/api/3/checkout',
				'http_method' => 'POST',
				'fields'      => [
					'data'      => 'b64',
					'signature' => 'sig',
					'version'   => '3',
				],
			],
			$result
		);
	}
}
