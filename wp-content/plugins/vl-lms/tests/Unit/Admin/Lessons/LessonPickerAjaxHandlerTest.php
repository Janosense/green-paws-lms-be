<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Admin\Lessons;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Admin\Lessons\LessonPickerAjaxHandler;
use WP_Post;

final class LessonPickerAjaxHandlerTest extends TestCase {

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

	private static function fakeLesson( int $id, int $parent_id, string $title ): WP_Post {
		$post              = Mockery::mock( 'WP_Post' );
		$post->ID          = $id;
		$post->post_type   = 'vl_lesson';
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

		$this->invoke( fn () => ( new LessonPickerAjaxHandler() )->search() );

		self::assertCount( 1, $this->error_calls );
		self::assertSame( 403, $this->error_calls[0][1] );
	}

	public function test_search_returns_mapped_unattached_lessons(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_posts' )->justReturn(
			[
				self::fakeLesson( 5, 0, 'Alpha' ),
				self::fakeLesson( 6, 0, 'Beta' ),
			]
		);

		$_REQUEST = [ 'nonce' => 'good' ];
		$_GET     = [
			'nonce' => 'good',
			'q'     => 'a',
		];

		$this->invoke( fn () => ( new LessonPickerAjaxHandler() )->search() );

		self::assertCount( 1, $this->success_calls );
		self::assertSame(
			[
				[
					'id'    => 5,
					'title' => 'Alpha',
				],
				[
					'id'    => 6,
					'title' => 'Beta',
				],
			],
			$this->success_calls[0]
		);
	}

	public function test_attach_rejects_already_attached_lesson(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_post_type' )->justReturn( 'vl_course' );
		Functions\when( 'get_post' )->justReturn( self::fakeLesson( 9, 123, 'Owned' ) );

		$_REQUEST = [ 'nonce' => 'good' ];
		$_POST    = [
			'nonce'     => 'good',
			'course_id' => '42',
			'lesson_id' => '9',
		];

		$this->invoke( fn () => ( new LessonPickerAjaxHandler() )->attach() );

		self::assertCount( 1, $this->error_calls );
		self::assertSame( 409, $this->error_calls[0][1] );
		self::assertSame( [], $this->update_calls );
	}

	public function test_attach_rejects_non_lesson(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_post_type' )->justReturn( 'vl_course' );
		$module            = Mockery::mock( 'WP_Post' );
		$module->ID        = 9;
		$module->post_type = 'vl_module';
		Functions\when( 'get_post' )->justReturn( $module );

		$_REQUEST = [ 'nonce' => 'good' ];
		$_POST    = [
			'nonce'     => 'good',
			'course_id' => '42',
			'lesson_id' => '9',
		];

		$this->invoke( fn () => ( new LessonPickerAjaxHandler() )->attach() );

		self::assertCount( 1, $this->error_calls );
		self::assertSame( 400, $this->error_calls[0][1] );
		self::assertSame( [], $this->update_calls );
	}

	public function test_attach_writes_parent_and_menu_order(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_post_type' )->justReturn( 'vl_course' );
		Functions\when( 'get_post' )->justReturn( self::fakeLesson( 9, 0, 'Free lesson' ) );

		// next_menu_order() lookup returns one existing sibling at order 3.
		Functions\when( 'get_posts' )->justReturn( [ self::fakeLessonWithOrder( 4, 3 ) ] );

		$_REQUEST = [ 'nonce' => 'good' ];
		$_POST    = [
			'nonce'     => 'good',
			'course_id' => '42',
			'lesson_id' => '9',
		];

		$this->invoke( fn () => ( new LessonPickerAjaxHandler() )->attach() );

		self::assertSame( [], $this->error_calls );
		self::assertCount( 1, $this->update_calls );
		self::assertSame( 9, $this->update_calls[0]['ID'] );
		self::assertSame( 42, $this->update_calls[0]['post_parent'] );
		self::assertSame( 4, $this->update_calls[0]['menu_order'] );
		self::assertCount( 1, $this->success_calls );
		self::assertSame( 9, $this->success_calls[0]['id'] );
	}

	public function test_detach_zeroes_parent(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_post_type' )->justReturn( 'vl_lesson' );

		$_REQUEST = [ 'nonce' => 'good' ];
		$_POST    = [
			'nonce'     => 'good',
			'lesson_id' => '9',
		];

		$this->invoke( fn () => ( new LessonPickerAjaxHandler() )->detach() );

		self::assertSame( [], $this->error_calls );
		self::assertCount( 1, $this->update_calls );
		self::assertSame( 9, $this->update_calls[0]['ID'] );
		self::assertSame( 0, $this->update_calls[0]['post_parent'] );
	}

	private static function fakeLessonWithOrder( int $id, int $order ): WP_Post {
		$post             = self::fakeLesson( $id, 42, 'Sibling' );
		$post->menu_order = $order;
		return $post;
	}
}
