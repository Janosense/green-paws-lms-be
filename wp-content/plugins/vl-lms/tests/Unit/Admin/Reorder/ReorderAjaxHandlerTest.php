<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Admin\Reorder;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use stdClass;
use VL\LMS\Admin\Reorder\ReorderAjaxHandler;

final class ReorderAjaxHandlerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var list<array{0: array<string, mixed>, 1: int}> */
	private array $error_calls = [];

	/** @var list<array<string, mixed>> */
	private array $success_calls = [];

	/** @var list<array<string, mixed>> */
	private array $update_calls = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'absint' )->alias( static fn ( $v ): int => (int) $v );

		$this->error_calls   = [];
		$this->success_calls = [];
		$this->update_calls  = [];

		$errors  = &$this->error_calls;
		$success = &$this->success_calls;
		$updates = &$this->update_calls;

		Functions\when( 'wp_send_json_error' )->alias(
			static function ( $data = null, $status = null ) use ( &$errors ): void {
				$errors[] = [ is_array( $data ) ? $data : [ 'data' => $data ], (int) $status ];
				throw new \RuntimeException( 'wp_send_json_error' );
			}
		);
		Functions\when( 'wp_send_json_success' )->alias(
			static function ( $data = null ) use ( &$success ): void {
				$success[] = is_array( $data ) ? $data : [ 'data' => $data ];
				throw new \RuntimeException( 'wp_send_json_success' );
			}
		);
		Functions\when( 'wp_update_post' )->alias(
			static function ( array $args ) use ( &$updates ): int {
				$updates[] = $args;
				return (int) ( $args['ID'] ?? 0 );
			}
		);

		$_POST = [];
	}

	protected function tearDown(): void {
		$_POST = [];
		Monkey\tearDown();
		parent::tearDown();
	}

	private static function fakePost( int $id ): stdClass {
		$post     = new stdClass();
		$post->ID = $id;
		return $post;
	}

	private function invoke( ReorderAjaxHandler $handler ): void {
		try {
			$handler->handle();
		} catch ( \RuntimeException $e ) {
			if ( ! str_starts_with( $e->getMessage(), 'wp_send_json_' ) ) {
				throw $e;
			}
		}
	}

	public function test_handle_sends_403_on_bad_nonce(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( false );
		Functions\when( 'get_post' )->justReturn( null );
		Functions\when( 'current_user_can' )->justReturn( true );

		$_POST = [
			'nonce' => 'bad',
			'ids'   => [ 1, 2 ],
		];

		$this->invoke( new ReorderAjaxHandler() );

		self::assertCount( 1, $this->error_calls );
		self::assertSame( 403, $this->error_calls[0][1] );
		self::assertSame( [], $this->update_calls );
	}

	public function test_handle_sends_400_when_ids_empty(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'get_post' )->justReturn( null );
		Functions\when( 'current_user_can' )->justReturn( true );

		$_POST = [
			'nonce' => 'good',
			'ids'   => [],
		];

		$this->invoke( new ReorderAjaxHandler() );

		self::assertCount( 1, $this->error_calls );
		self::assertSame( 400, $this->error_calls[0][1] );
		self::assertSame( [], $this->update_calls );
	}

	public function test_handle_sends_400_when_too_many_ids(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'get_post' )->justReturn( null );
		Functions\when( 'current_user_can' )->justReturn( true );

		$_POST = [
			'nonce' => 'good',
			'ids'   => range( 1, 201 ),
		];

		$this->invoke( new ReorderAjaxHandler() );

		self::assertCount( 1, $this->error_calls );
		self::assertSame( 400, $this->error_calls[0][1] );
		self::assertSame( [], $this->update_calls );
	}

	public function test_handle_skips_post_when_capability_denied(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'get_post' )->alias(
			static fn ( int $id ): ?stdClass => self::fakePost( $id )
		);
		Functions\when( 'current_user_can' )->alias(
			static fn ( string $cap, int $id ): bool => 1 !== $id
		);

		$_POST = [
			'nonce' => 'good',
			'ids'   => [ 1, 2 ],
		];

		$this->invoke( new ReorderAjaxHandler() );

		self::assertSame( [], $this->error_calls );
		self::assertCount( 1, $this->update_calls );
		self::assertSame( 2, $this->update_calls[0]['ID'] );
		self::assertCount( 1, $this->success_calls );
		self::assertSame( [ 'updated' => 1 ], $this->success_calls[0] );
	}
}
