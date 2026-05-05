<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Admin\MetaBoxes;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Admin\MetaBoxes\QuizMetaBox;
use WP_Post;

final class QuizMetaBoxTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const string NONCE_FIELD = 'vl_lms_vl_lms_quiz_nonce';

	/** @var list<array{0: int, 1: string, 2: mixed}> */
	private array $writes = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_post_type' )->justReturn( 'vl_quiz' );

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

	public function test_save_clamps_passing_threshold_above_100(): void {
		$_POST = [
			self::NONCE_FIELD            => 'nonce-x',
			'_vl_quiz_passing_threshold' => '999',
		];

		$post = Mockery::mock( 'WP_Post' );
		assert( $post instanceof WP_Post );
		( new QuizMetaBox() )->save( 9, $post );

		$threshold_writes = array_values(
			array_filter(
				$this->writes,
				static fn ( array $row ): bool => '_vl_quiz_passing_threshold' === $row[1]
			)
		);
		self::assertCount( 1, $threshold_writes );
		self::assertSame( 100, $threshold_writes[0][2] );
	}
}
