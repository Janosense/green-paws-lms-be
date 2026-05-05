<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Payments\LiqPay;

use PHPUnit\Framework\TestCase;
use VL\LMS\Payments\Exception\PaymentProviderHttpException;
use VL\LMS\Payments\LiqPay\RefundResponseParser;

final class RefundResponseParserTest extends TestCase {

	public function test_parses_reversed_response(): void {
		$body   = json_encode(
			[
				'status'     => 'reversed',
				'payment_id' => 42,
				'amount'     => 1500.0,
			]
		);
		$parser = new RefundResponseParser();

		$response = $parser->parse( (string) $body );

		self::assertSame( 'reversed', $response->status );
		self::assertSame( '42', $response->payment_id );
		self::assertNull( $response->err_code );
		self::assertSame( 42, $response->raw['payment_id'] );
	}

	public function test_parses_string_payment_id(): void {
		$body     = json_encode(
			[
				'status'     => 'reversed',
				'payment_id' => '987654321',
			]
		);
		$response = ( new RefundResponseParser() )->parse( (string) $body );

		self::assertSame( '987654321', $response->payment_id );
	}

	public function test_parses_error_response(): void {
		$body   = json_encode(
			[
				'status'          => 'error',
				'err_code'        => 'err_signature',
				'err_description' => 'invalid signature',
			]
		);
		$parser = new RefundResponseParser();

		$response = $parser->parse( (string) $body );

		self::assertSame( 'error', $response->status );
		self::assertNull( $response->payment_id );
		self::assertSame( 'err_signature', $response->err_code );
		self::assertSame( 'invalid signature', $response->err_description );
	}

	public function test_parses_failure_response(): void {
		$body   = json_encode(
			[
				'status'   => 'failure',
				'err_code' => 'err_amount',
			]
		);
		$parser = new RefundResponseParser();

		$response = $parser->parse( (string) $body );

		self::assertSame( 'failure', $response->status );
		self::assertSame( 'err_amount', $response->err_code );
	}

	public function test_throws_when_body_not_json(): void {
		$this->expectException( PaymentProviderHttpException::class );
		( new RefundResponseParser() )->parse( '<!DOCTYPE html><html>oops</html>' );
	}

	public function test_throws_when_body_is_json_array_not_object(): void {
		$this->expectException( PaymentProviderHttpException::class );
		( new RefundResponseParser() )->parse( '"plain string"' );
	}

	public function test_throws_when_status_field_missing(): void {
		$this->expectException( PaymentProviderHttpException::class );
		( new RefundResponseParser() )->parse( '{"payment_id":1}' );
	}

	public function test_throws_when_status_field_empty(): void {
		$this->expectException( PaymentProviderHttpException::class );
		( new RefundResponseParser() )->parse( '{"status":""}' );
	}
}
