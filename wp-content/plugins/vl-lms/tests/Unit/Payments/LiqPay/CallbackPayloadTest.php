<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Payments\LiqPay;

use PHPUnit\Framework\TestCase;
use VL\LMS\Payments\LiqPay\CallbackPayload;

final class CallbackPayloadTest extends TestCase {

	public function test_getters_round_trip_construction(): void {
		$raw  = [
			'order_id'   => 'uuid-123',
			'status'     => 'success',
			'action'     => 'pay',
			'payment_id' => 999,
			'amount'     => 1500.00,
			'currency'   => 'UAH',
		];
		$json = (string) json_encode( $raw );

		$payload = new CallbackPayload(
			order_id: 'uuid-123',
			status: 'success',
			action: 'pay',
			payment_id: '999',
			amount: '1500.00',
			currency: 'UAH',
			raw_payload_json: $json,
			raw_payload: $raw
		);

		self::assertSame( 'uuid-123', $payload->order_id() );
		self::assertSame( 'success', $payload->status() );
		self::assertSame( 'pay', $payload->action() );
		self::assertSame( '999', $payload->payment_id() );
		self::assertSame( '1500.00', $payload->amount() );
		self::assertSame( 'UAH', $payload->currency() );
		self::assertSame( $json, $payload->raw_payload_json() );
		self::assertSame( $raw, $payload->raw_payload() );
	}

	public function test_to_idempotency_key_formats_three_part_string(): void {
		$payload = $this->payload( payment_id: '999', action: 'pay', status: 'success' );

		self::assertSame( 'liqpay:999:pay:success', $payload->to_idempotency_key() );
	}

	public function test_to_idempotency_key_throws_when_payment_id_missing(): void {
		$payload = $this->payload( payment_id: null );

		$this->expectException( \LogicException::class );
		$payload->to_idempotency_key();
	}

	public function test_payment_id_can_be_null(): void {
		$payload = $this->payload( payment_id: null );

		self::assertNull( $payload->payment_id() );
	}

	private function payload(
		?string $payment_id = '999',
		string $action = 'pay',
		string $status = 'success'
	): CallbackPayload {
		return new CallbackPayload(
			order_id: 'uuid-123',
			status: $status,
			action: $action,
			payment_id: $payment_id,
			amount: '1500.00',
			currency: 'UAH',
			raw_payload_json: '{}',
			raw_payload: []
		);
	}
}
