<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Api;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Api\PaymentsController;
use VL\LMS\Payments\LiqPay\CallbackHandler;
use VL\LMS\Payments\LiqPay\CallbackOutcome;
use VL\LMS\Payments\LiqPay\CallbackParser;
use VL\LMS\Payments\LiqPay\CallbackPayload;
use VL\LMS\Support\Logger;
use WP_REST_Request;
use WP_REST_Response;

final class PaymentsControllerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface&CallbackParser */
	private $parser;

	/** @var Mockery\MockInterface&CallbackHandler */
	private $handler;

	/** @var Mockery\MockInterface&Logger */
	private $logger;

	private PaymentsController $controller;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'rest_ensure_response' )->alias(
			static function ( $payload ): WP_REST_Response {
				$response         = Mockery::mock( WP_REST_Response::class )->makePartial();
				$response->status = 200;
				$response->shouldReceive( 'set_status' )->andReturnUsing(
					function ( int $status ) use ( $response ): WP_REST_Response {
						$response->status = $status;
						return $response;
					}
				);
				$response->shouldReceive( 'get_status' )->andReturnUsing(
					static fn (): int => $response->status
				);
				$response->shouldReceive( 'get_data' )->andReturn( $payload );
				return $response;
			}
		);

		$this->parser  = Mockery::mock( CallbackParser::class );
		$this->handler = Mockery::mock( CallbackHandler::class );
		$this->logger  = Mockery::mock( Logger::class );
		$this->logger->shouldIgnoreMissing();

		$this->controller = new PaymentsController(
			'vl/v1',
			$this->parser,
			$this->handler,
			$this->logger
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_register_routes_calls_register_rest_route_with_public_permission(): void {
		$captured = null;
		Functions\expect( 'register_rest_route' )
			->once()
			->andReturnUsing(
				static function ( $namespace, $route, $args ) use ( &$captured ): bool {
					$captured = [
						'namespace' => $namespace,
						'route'     => $route,
						'args'      => $args,
					];
					return true;
				}
			);

		$this->controller->register_routes();

		self::assertSame( 'vl/v1', $captured['namespace'] );
		self::assertSame( '/payments/liqpay/callback', $captured['route'] );
		self::assertSame( 'POST', $captured['args']['methods'] );
		self::assertSame( '__return_true', $captured['args']['permission_callback'] );
	}

	public function test_callback_returns_200_for_happy_path(): void {
		$payload = $this->payload();
		$this->parser->shouldReceive( 'parse' )
			->with( 'data-b64', 'sig-b64' )
			->andReturn( $payload );
		$this->handler->shouldReceive( 'handle' )
			->with( $payload )
			->andReturn( CallbackOutcome::OK_PROCESSED );

		$response = $this->controller->callback( $this->request( 'data-b64', 'sig-b64' ) );

		self::assertInstanceOf( WP_REST_Response::class, $response );
		self::assertSame( 200, $response->get_status() );
	}

	public function test_callback_returns_200_when_data_param_missing(): void {
		$this->parser->shouldNotReceive( 'parse' );
		$this->handler->shouldNotReceive( 'handle' );

		$response = $this->controller->callback( $this->request( null, 'sig' ) );

		self::assertSame( 200, $response->get_status() );
	}

	public function test_callback_returns_200_when_signature_param_missing(): void {
		$this->parser->shouldNotReceive( 'parse' );
		$this->handler->shouldNotReceive( 'handle' );

		$response = $this->controller->callback( $this->request( 'data', null ) );

		self::assertSame( 200, $response->get_status() );
	}

	public function test_callback_returns_200_when_parser_returns_null(): void {
		$this->parser->shouldReceive( 'parse' )->andReturn( null );
		$this->handler->shouldNotReceive( 'handle' );

		$response = $this->controller->callback( $this->request( 'data', 'sig' ) );

		self::assertSame( 200, $response->get_status() );
	}

	public function test_callback_returns_200_for_every_outcome(): void {
		$payload = $this->payload();
		$this->parser->shouldReceive( 'parse' )->andReturn( $payload );

		$outcomes = [
			CallbackOutcome::OK_PROCESSED,
			CallbackOutcome::OK_DUPLICATE,
			CallbackOutcome::OK_NO_OP,
			CallbackOutcome::OK_UNKNOWN_ORDER,
			CallbackOutcome::OK_AMOUNT_MISMATCH,
			CallbackOutcome::OK_CURRENCY_MISMATCH,
		];
		foreach ( $outcomes as $outcome ) {
			$this->handler->shouldReceive( 'handle' )->andReturn( $outcome )->byDefault();
			$response = $this->controller->callback( $this->request( 'data', 'sig' ) );
			self::assertSame( 200, $response->get_status() );
		}
	}

	private function request( ?string $data, ?string $signature ): WP_REST_Request {
		$req = Mockery::mock( WP_REST_Request::class );
		$req->shouldReceive( 'get_param' )->andReturnUsing(
			static function ( string $name ) use ( $data, $signature ): ?string {
				return match ( $name ) {
					'data' => $data,
					'signature' => $signature,
					default => null,
				};
			}
		);
		return $req;
	}

	private function payload(): CallbackPayload {
		return new CallbackPayload(
			order_id: 'uuid-x',
			status: 'success',
			action: 'pay',
			payment_id: '1',
			amount: '1500.00',
			currency: 'UAH',
			raw_payload_json: '{}',
			raw_payload: []
		);
	}
}
