<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Admin\MetaBoxes;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Admin\MetaBoxes\ModuleMetaBox;
use WP_Post;

final class ModuleMetaBoxTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const string NONCE_FIELD = 'vl_lms_vl_lms_module_nonce';

	/** @var list<array{0: int, 1: string, 2: mixed}> */
	private array $writes = [];

	/** @var list<array<string, mixed>> */
	private array $post_updates = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_post_type' )->alias(
			static function ( int|string $id ): string {
				return match ( (int) $id ) {
					77      => 'vl_course',
					88      => 'page',
					default => 'vl_module',
				};
			}
		);
		Functions\when( 'get_post_field' )->justReturn( 0 );

		$this->writes       = [];
		$this->post_updates = [];
		$writes             = &$this->writes;
		$post_updates       = &$this->post_updates;
		Functions\when( 'update_post_meta' )->alias(
			static function ( int $id, string $key, mixed $value ) use ( &$writes ): bool {
				$writes[] = [ $id, $key, $value ];
				return true;
			}
		);
		Functions\when( 'wp_update_post' )->alias(
			static function ( array $postarr ) use ( &$post_updates ): int {
				$post_updates[] = $postarr;
				return (int) ( $postarr['ID'] ?? 0 );
			}
		);

		$_POST = [];
	}

	protected function tearDown(): void {
		$_POST = [];
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_render_preselects_course_from_query_string_hint(): void {
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_the_title' )->justReturn( '' );
		Functions\when( 'selected' )->alias(
			static fn ( $a, $b ): string => (string) $a === (string) $b ? ' selected' : ''
		);
		$course             = Mockery::mock( 'WP_Post' );
		$course->ID         = 77;
		$course->post_title = 'Self-paced course';
		Functions\when( 'get_posts' )->justReturn( [ $course ] );

		$_GET = [ 'vl_parent_id' => '77' ];

		$post              = Mockery::mock( 'WP_Post' );
		$post->ID          = 0;
		$post->post_parent = 0;
		assert( $post instanceof WP_Post );

		ob_start();
		( new ModuleMetaBox() )->render( $post );
		$html = (string) ob_get_clean();

		$_GET = [];

		self::assertStringContainsString( '<option value="77" selected>', $html );
	}

	public function test_save_does_not_write_removed_parameter_fields(): void {
		$_POST = [
			self::NONCE_FIELD              => 'nonce-x',
			'_vl_module_intro_video_url'   => 'https://example.test/intro',
			'_vl_module_duration_minutes'  => '42',
			'_vl_module_passing_threshold' => '150',
		];

		$post = Mockery::mock( 'WP_Post' );
		assert( $post instanceof WP_Post );
		( new ModuleMetaBox() )->save( 7, $post );

		self::assertSame( [], $this->writes );
	}

	public function test_save_updates_post_parent_when_course_id_changes(): void {
		Functions\when( 'get_post_field' )->justReturn( 0 );

		$_POST = [
			self::NONCE_FIELD      => 'nonce-x',
			'_vl_module_course_id' => '77',
		];

		$post = Mockery::mock( 'WP_Post' );
		assert( $post instanceof WP_Post );
		( new ModuleMetaBox() )->save( 44, $post );

		self::assertCount( 1, $this->post_updates );
		self::assertSame( 44, $this->post_updates[0]['ID'] );
		self::assertSame( 77, $this->post_updates[0]['post_parent'] );
	}

	public function test_save_skips_post_parent_update_when_course_id_unchanged(): void {
		Functions\when( 'get_post_field' )->justReturn( 77 );

		$_POST = [
			self::NONCE_FIELD      => 'nonce-x',
			'_vl_module_course_id' => '77',
		];

		$post = Mockery::mock( 'WP_Post' );
		assert( $post instanceof WP_Post );
		( new ModuleMetaBox() )->save( 44, $post );

		self::assertSame( [], $this->post_updates );
	}

	public function test_save_rejects_post_parent_pointing_at_non_course(): void {
		Functions\when( 'get_post_field' )->justReturn( 0 );

		$_POST = [
			self::NONCE_FIELD      => 'nonce-x',
			'_vl_module_course_id' => '88',
		];

		$post = Mockery::mock( 'WP_Post' );
		assert( $post instanceof WP_Post );
		( new ModuleMetaBox() )->save( 44, $post );

		self::assertSame( [], $this->post_updates );
	}

	public function test_save_clears_post_parent_when_course_id_zero(): void {
		Functions\when( 'get_post_field' )->justReturn( 77 );

		$_POST = [
			self::NONCE_FIELD      => 'nonce-x',
			'_vl_module_course_id' => '0',
		];

		$post = Mockery::mock( 'WP_Post' );
		assert( $post instanceof WP_Post );
		( new ModuleMetaBox() )->save( 44, $post );

		self::assertCount( 1, $this->post_updates );
		self::assertSame( 44, $this->post_updates[0]['ID'] );
		self::assertSame( 0, $this->post_updates[0]['post_parent'] );
	}
}
