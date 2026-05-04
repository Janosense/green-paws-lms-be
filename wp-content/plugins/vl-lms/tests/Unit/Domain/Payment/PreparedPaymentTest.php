<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Domain\Payment;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Payment\PreparedPayment;

final class PreparedPaymentTest extends TestCase {

	public function test_constructs_with_valid_fields(): void {
		$prepared = new PreparedPayment(
			action_url: 'https://www.liqpay.ua/api/3/checkout',
			http_method: 'POST',
			fields: [
				'data'      => 'b64-payload',
				'signature' => 'sig',
				'version'   => '3',
			]
		);

		self::assertSame( 'https://www.liqpay.ua/api/3/checkout', $prepared->action_url );
		self::assertSame( 'POST', $prepared->http_method );
		self::assertSame(
			[
				'data'      => 'b64-payload',
				'signature' => 'sig',
				'version'   => '3',
			],
			$prepared->fields
		);
	}

	public function test_to_array_round_trips_all_three_keys(): void {
		$prepared = new PreparedPayment(
			'https://example.test',
			'POST',
			[
				'data'      => 'a',
				'signature' => 'b',
				'version'   => '3',
			]
		);

		$array = $prepared->to_array();

		self::assertSame(
			[
				'action_url'  => 'https://example.test',
				'http_method' => 'POST',
				'fields'      => [
					'data'      => 'a',
					'signature' => 'b',
					'version'   => '3',
				],
			],
			$array
		);
	}

	public function test_empty_action_url_throws(): void {
		$this->expectException( InvalidArgumentException::class );
		new PreparedPayment( '', 'POST', [ 'data' => 'a' ] );
	}

	public function test_empty_http_method_throws(): void {
		$this->expectException( InvalidArgumentException::class );
		new PreparedPayment( 'https://x', '', [ 'data' => 'a' ] );
	}

	public function test_empty_fields_throws(): void {
		$this->expectException( InvalidArgumentException::class );
		new PreparedPayment( 'https://x', 'POST', [] );
	}

	public function test_empty_field_value_throws(): void {
		$this->expectException( InvalidArgumentException::class );
		new PreparedPayment( 'https://x', 'POST', [ 'data' => '' ] );
	}

	public function test_non_string_field_key_throws(): void {
		$this->expectException( InvalidArgumentException::class );
		/** @phpstan-ignore-next-line — intentionally wrong key shape */
		new PreparedPayment( 'https://x', 'POST', [ 0 => 'a' ] );
	}
}
