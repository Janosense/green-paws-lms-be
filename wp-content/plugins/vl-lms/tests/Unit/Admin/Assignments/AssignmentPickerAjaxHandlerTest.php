<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Admin\Assignments;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Admin\Assignments\AssignmentPickerAjaxHandler;
use WP_Post;

final class AssignmentPickerAjaxHandlerTest extends TestCase {

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
		Functions\when( 'get_edit_post_link' )->justReturn( 'https://admin.test/edit' );
		Functions\when( 'get_posts' )->justReturn( [] );

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

		$_GET     = [];
		$_POST    = [];
		$_REQUEST = [];
	}

	protected function tearDown(): void {
		$_GET     = [];
		$_POST    = [];
		$_REQUEST = [];
		Monkey\tearDown();
		parent::tearDown();
	}

	private static function fakeAssignment( int $id, int $parent_id, string $title ): WP_Post {
		$post              = Mockery::mock( 'WP_Post' );
		$post->ID          = $id;
		$post->post_type   = 'vl_assignment';
		$post->post_parent = $parent_id;
		$post->post_title  = $title;
		$post->menu_order  = 0;
		assert( $post instanceof WP_Post );
		return $post;
	}

	/**
	 * @param callable():void $callback
	 */
	private function invoke( callable $callback ): void {
		try {
			$callback();
		} catch ( \RuntimeException $e ) {
			if ( ! str_starts_with( $e->getMessage(), 'wp_send_json_' ) ) {
				throw $e;
			}
		}
	}

	public function test_search_sends_403_on_bad_nonce(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( false );
		Functions\when( 'current_user_can' )->justReturn( true );

		$_REQUEST = [ 'nonce' => 'bad' ];

		$this->invoke( fn () => ( new AssignmentPickerAjaxHandler() )->search() );

		self::assertCount( 1, $this->error_calls );
		self::assertSame( 403, $this->error_calls[0][1] );
	}

	public function test_attach_rejects_already_attached_assignment(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_post_type' )->justReturn( 'vl_lesson' );
		Functions\when( 'get_post' )->justReturn( self::fakeAssignment( 9, 123, 'Owned' ) );

		$_REQUEST = [ 'nonce' => 'good' ];
		$_POST    = [
			'nonce'         => 'good',
			'parent_id'     => '42',
			'assignment_id' => '9',
		];

		$this->invoke( fn () => ( new AssignmentPickerAjaxHandler() )->attach() );

		self::assertCount( 1, $this->error_calls );
		self::assertSame( 409, $this->error_calls[0][1] );
		self::assertSame( [], $this->update_calls );
	}

	public function test_attach_rejects_parent_that_is_not_a_curriculum_node(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_post_type' )->justReturn( 'vl_quiz' );

		$_REQUEST = [ 'nonce' => 'good' ];
		$_POST    = [
			'nonce'         => 'good',
			'parent_id'     => '42',
			'assignment_id' => '9',
		];

		$this->invoke( fn () => ( new AssignmentPickerAjaxHandler() )->attach() );

		self::assertCount( 1, $this->error_calls );
		self::assertSame( 400, $this->error_calls[0][1] );
		self::assertSame( [], $this->update_calls );
	}

	/**
	 * @dataProvider parentTypeProvider
	 */
	public function test_attach_writes_parent_for_each_allowed_parent_type( string $parent_type ): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_post_type' )->justReturn( $parent_type );
		Functions\when( 'get_post' )->justReturn( self::fakeAssignment( 9, 0, 'Free assignment' ) );
		Functions\when( 'get_posts' )->justReturn( [] );

		$_REQUEST = [ 'nonce' => 'good' ];
		$_POST    = [
			'nonce'         => 'good',
			'parent_id'     => '42',
			'assignment_id' => '9',
		];

		$this->invoke( fn () => ( new AssignmentPickerAjaxHandler() )->attach() );

		self::assertSame( [], $this->error_calls );
		self::assertCount( 1, $this->update_calls );
		self::assertSame( 9, $this->update_calls[0]['ID'] );
		self::assertSame( 42, $this->update_calls[0]['post_parent'] );
		self::assertSame( 0, $this->update_calls[0]['menu_order'] );
	}

	/**
	 * @return list<list<string>>
	 */
	public static function parentTypeProvider(): array {
		return [
			[ 'vl_course' ],
			[ 'vl_module' ],
			[ 'vl_lesson' ],
			[ 'vl_session' ],
		];
	}

	public function test_detach_zeroes_parent(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_post_type' )->justReturn( 'vl_assignment' );

		$_REQUEST = [ 'nonce' => 'good' ];
		$_POST    = [
			'nonce'         => 'good',
			'assignment_id' => '9',
		];

		$this->invoke( fn () => ( new AssignmentPickerAjaxHandler() )->detach() );

		self::assertSame( [], $this->error_calls );
		self::assertCount( 1, $this->update_calls );
		self::assertSame( 9, $this->update_calls[0]['ID'] );
		self::assertSame( 0, $this->update_calls[0]['post_parent'] );
	}
}
