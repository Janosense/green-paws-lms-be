<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Payments\LiqPay;

use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Payments\LiqPay\CallbackParser;
use VL\LMS\Payments\LiqPay\SignatureVerifier;

final class CallbackParserTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface&SignatureVerifier */
	private $verifier;

	private CallbackParser $parser;

	protected function setUp(): void {
		parent::setUp();
		$this->verifier = Mockery::mock( SignatureVerifier::class );
		$this->parser   = new CallbackParser( $this->verifier );
	}

	public function test_returns_payload_for_well_formed_input(): void {
		$base64 = $this->encode(
			[
				'order_id'   => 'uuid-123',
				'status'     => 'success',
				'action'     => 'pay',
				'amount'     => 1500.0,
				'currency'   => 'UAH',
				'payment_id' => 999,
			]
		);
		$this->verifier->shouldReceive( 'verify' )->andReturn( true );

		$payload = $this->parser->parse( $base64, 'sig' );

		self::assertNotNull( $payload );
		self::assertSame( 'uuid-123', $payload->order_id() );
		self::assertSame( 'success', $payload->status() );
		self::assertSame( 'pay', $payload->action() );
		self::assertSame( '1500.00', $payload->amount() );
		self::assertSame( 'UAH', $payload->currency() );
		self::assertSame( '999', $payload->payment_id() );
	}

	public function test_returns_null_when_signature_verification_fails(): void {
		$this->verifier->shouldReceive( 'verify' )->andReturn( false );

		self::assertNull( $this->parser->parse( 'data', 'sig' ) );
	}

	public function test_returns_null_for_invalid_base64(): void {
		$this->verifier->shouldReceive( 'verify' )->andReturn( true );

		self::assertNull( $this->parser->parse( '!!!not-base64!!!', 'sig' ) );
	}

	public function test_returns_null_for_invalid_json(): void {
		$this->verifier->shouldReceive( 'verify' )->andReturn( true );
		$base64 = base64_encode( 'this-is-not-json' );

		self::assertNull( $this->parser->parse( $base64, 'sig' ) );
	}

	public function test_returns_null_when_required_field_missing(): void {
		$this->verifier->shouldReceive( 'verify' )->andReturn( true );
		$base64 = $this->encode(
			[
				// `order_id` missing.
				'status'   => 'success',
				'action'   => 'pay',
				'amount'   => 1500.0,
				'currency' => 'UAH',
			]
		);

		self::assertNull( $this->parser->parse( $base64, 'sig' ) );
	}

	public function test_returns_null_when_amount_is_not_numeric(): void {
		$this->verifier->shouldReceive( 'verify' )->andReturn( true );
		$base64 = $this->encode(
			[
				'order_id' => 'uuid',
				'status'   => 'success',
				'action'   => 'pay',
				'amount'   => 'not-a-number',
				'currency' => 'UAH',
			]
		);

		self::assertNull( $this->parser->parse( $base64, 'sig' ) );
	}

	public function test_amount_normalizes_integer_to_two_decimal_string(): void {
		$this->verifier->shouldReceive( 'verify' )->andReturn( true );
		$base64 = $this->encode(
			[
				'order_id'   => 'uuid',
				'status'     => 'success',
				'action'     => 'pay',
				'amount'     => 1500,
				'currency'   => 'UAH',
				'payment_id' => 1,
			]
		);

		$payload = $this->parser->parse( $base64, 'sig' );

		self::assertNotNull( $payload );
		self::assertSame( '1500.00', $payload->amount() );
	}

	public function test_amount_normalizes_string_decimal(): void {
		$this->verifier->shouldReceive( 'verify' )->andReturn( true );
		$base64 = $this->encode(
			[
				'order_id'   => 'uuid',
				'status'     => 'success',
				'action'     => 'pay',
				'amount'     => '99.5',
				'currency'   => 'UAH',
				'payment_id' => 1,
			]
		);

		$payload = $this->parser->parse( $base64, 'sig' );

		self::assertNotNull( $payload );
		self::assertSame( '99.50', $payload->amount() );
	}

	public function test_payment_id_string_form_is_preserved(): void {
		$this->verifier->shouldReceive( 'verify' )->andReturn( true );
		$base64 = $this->encode(
			[
				'order_id'   => 'uuid',
				'status'     => 'success',
				'action'     => 'pay',
				'amount'     => 1.0,
				'currency'   => 'UAH',
				'payment_id' => '42',
			]
		);

		$payload = $this->parser->parse( $base64, 'sig' );

		self::assertNotNull( $payload );
		self::assertSame( '42', $payload->payment_id() );
	}

	public function test_missing_payment_id_is_allowed_at_parse_time(): void {
		$this->verifier->shouldReceive( 'verify' )->andReturn( true );
		$base64 = $this->encode(
			[
				'order_id' => 'uuid',
				'status'   => 'success',
				'action'   => 'pay',
				'amount'   => 1.0,
				'currency' => 'UAH',
			]
		);

		$payload = $this->parser->parse( $base64, 'sig' );

		self::assertNotNull( $payload );
		self::assertNull( $payload->payment_id() );
	}

	public function test_raw_payload_json_holds_decoded_string(): void {
		$this->verifier->shouldReceive( 'verify' )->andReturn( true );
		$json   = '{"order_id":"uuid","status":"success","action":"pay","amount":1,"currency":"UAH","payment_id":1}';
		$base64 = base64_encode( $json );

		$payload = $this->parser->parse( $base64, 'sig' );

		self::assertNotNull( $payload );
		self::assertSame( $json, $payload->raw_payload_json() );
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function encode( array $data ): string {
		return base64_encode( (string) json_encode( $data ) );
	}
}
