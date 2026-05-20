<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Admin\MetaBoxes;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Admin\MetaBoxes\TopicMetaBox;
use WP_Post;

final class TopicMetaBoxTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const string NONCE_FIELD = 'vl_lms_vl_lms_topic_nonce';

	/** @var list<array{0: int, 1: string, 2: mixed}> */
	private array $writes = [];

	/** @var list<array<string, mixed>> */
	private array $post_updates = [];

	private int $topic_parent = 0;

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
					55      => 'vl_lesson',
					56      => 'vl_lesson',
					77      => 'vl_course',
					99      => 'vl_module',
					88      => 'page',
					default => 'vl_topic',
				};
			}
		);

		$this->topic_parent = 0;
		$topic_parent       = &$this->topic_parent;
		Functions\when( 'get_post_field' )->alias(
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- signature must match WP.
			static function ( string $field, int $id ) use ( &$topic_parent ): int {
				if ( 'post_parent' !== $field ) {
					return 0;
				}
				return $topic_parent;
			}
		);

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
		$_GET  = [];
	}

	protected function tearDown(): void {
		$_POST = [];
		$_GET  = [];
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_save_writes_post_parent_to_lesson_when_selected(): void {
		$this->topic_parent = 0;

		$_POST = [
			self::NONCE_FIELD     => 'nonce-x',
			'_vl_topic_lesson_id' => '55',
		];

		( new TopicMetaBox() )->save( 7, Mockery::mock( 'WP_Post' ) );

		self::assertCount( 1, $this->post_updates );
		self::assertSame( 7, $this->post_updates[0]['ID'] );
		self::assertSame( 55, $this->post_updates[0]['post_parent'] );
	}

	public function test_save_skips_update_when_lesson_unchanged(): void {
		$this->topic_parent = 55;

		$_POST = [
			self::NONCE_FIELD     => 'nonce-x',
			'_vl_topic_lesson_id' => '55',
		];

		( new TopicMetaBox() )->save( 7, Mockery::mock( 'WP_Post' ) );

		self::assertSame( [], $this->post_updates );
	}

	public function test_save_coerces_invalid_lesson_id_to_zero_clearing_parent(): void {
		$this->topic_parent = 55;

		$_POST = [
			self::NONCE_FIELD     => 'nonce-x',
			'_vl_topic_lesson_id' => '88',
		];

		( new TopicMetaBox() )->save( 7, Mockery::mock( 'WP_Post' ) );

		self::assertCount( 1, $this->post_updates );
		self::assertSame( 0, $this->post_updates[0]['post_parent'] );
	}

	public function test_save_clears_post_parent_when_lesson_id_zero(): void {
		$this->topic_parent = 55;

		$_POST = [
			self::NONCE_FIELD     => 'nonce-x',
			'_vl_topic_lesson_id' => '0',
		];

		( new TopicMetaBox() )->save( 7, Mockery::mock( 'WP_Post' ) );

		self::assertCount( 1, $this->post_updates );
		self::assertSame( 0, $this->post_updates[0]['post_parent'] );
	}

	public function test_save_rejects_unknown_video_provider(): void {
		$_POST = [
			self::NONCE_FIELD          => 'nonce-x',
			'_vl_topic_video_provider' => 'twitch',
		];

		( new TopicMetaBox() )->save( 7, Mockery::mock( 'WP_Post' ) );

		$provider_writes = array_filter(
			$this->writes,
			static fn ( array $row ): bool => '_vl_topic_video_provider' === $row[1]
		);
		self::assertSame( [], $provider_writes, 'Unknown provider must be skipped' );
	}

	public function test_save_persists_known_video_provider(): void {
		$_POST = [
			self::NONCE_FIELD          => 'nonce-x',
			'_vl_topic_video_provider' => 'vimeo',
		];

		( new TopicMetaBox() )->save( 7, Mockery::mock( 'WP_Post' ) );

		$provider_writes = array_values(
			array_filter(
				$this->writes,
				static fn ( array $row ): bool => '_vl_topic_video_provider' === $row[1]
			)
		);
		self::assertCount( 1, $provider_writes );
		self::assertSame( 'vimeo', $provider_writes[0][2] );
	}
}
