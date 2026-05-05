<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Payments\LiqPay;

use PHPUnit\Framework\TestCase;
use VL\LMS\Payments\LiqPay\RefundResponse;

final class RefundResponseTest extends TestCase {

	public function test_carries_all_fields(): void {
		$response = new RefundResponse(
			status: 'reversed',
			payment_id: '42',
			err_code: null,
			err_description: null,
			raw: [
				'status'     => 'reversed',
				'payment_id' => 42,
			]
		);

		self::assertSame( 'reversed', $response->status );
		self::assertSame( '42', $response->payment_id );
		self::assertNull( $response->err_code );
		self::assertNull( $response->err_description );
		self::assertSame( 'reversed', $response->raw['status'] );
	}

	public function test_is_reversed_true_for_reversed_status(): void {
		$response = new RefundResponse( 'reversed', '1', null, null, [] );
		self::assertTrue( $response->is_reversed() );
		self::assertFalse( $response->is_rejected() );
	}

	public function test_is_rejected_true_for_failure_and_error(): void {
		$failure = new RefundResponse( 'failure', null, 'err_amount', 'bad amount', [] );
		$error   = new RefundResponse( 'error', null, 'err_signature', 'bad sig', [] );

		self::assertTrue( $failure->is_rejected() );
		self::assertTrue( $error->is_rejected() );
		self::assertFalse( $failure->is_reversed() );
	}

	public function test_other_status_is_neither_reversed_nor_rejected(): void {
		$other = new RefundResponse( 'processing', null, null, null, [] );

		self::assertFalse( $other->is_reversed() );
		self::assertFalse( $other->is_rejected() );
	}
}
