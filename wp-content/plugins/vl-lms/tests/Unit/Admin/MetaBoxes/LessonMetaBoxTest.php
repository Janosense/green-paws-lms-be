<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Admin\MetaBoxes;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Admin\MetaBoxes\LessonMetaBox;
use WP_Post;

final class LessonMetaBoxTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const string NONCE_FIELD = 'vl_lms_vl_lms_lesson_nonce';

	/** @var list<array{0: int, 1: string, 2: mixed}> */
	private array $writes = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_post_type' )->justReturn( 'vl_lesson' );

		$this->writes = [];
		$writes       = &$this->writes;
		Functions\when( 'update_post_meta' )->alias(
			static function ( int $id, string $key, mixed $value ) use ( &$writes ): bool {
				$writes[] = [ $id, $key, $value ];
				return true;
			}
		);

		$_POST = [];
	}

	protected function tearDown(): void {
		$_POST = [];
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_save_rejects_unknown_video_provider(): void {
		$_POST = [
			self::NONCE_FIELD           => 'nonce-x',
			'_vl_lesson_video_provider' => 'twitch',
		];

		$post = Mockery::mock( 'WP_Post' );
		assert( $post instanceof WP_Post );
		( new LessonMetaBox() )->save( 13, $post );

		$provider_writes = array_filter(
			$this->writes,
			static fn ( array $row ): bool => '_vl_lesson_video_provider' === $row[1]
		);
		self::assertSame( [], $provider_writes, 'Unknown provider must be skipped' );
	}
}
