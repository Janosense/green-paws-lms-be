<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Admin\MetaBoxes;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Admin\MetaBoxes\QuizQuestionMetaBox;
use WP_Post;

final class QuizQuestionMetaBoxTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const string NONCE_FIELD = 'vl_lms_vl_lms_quiz_question_nonce';

	/** @var list<array{0: int, 1: string, 2: mixed}> */
	private array $writes = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias(
			static fn ( $v ): string|false => json_encode( $v ) // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		);
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_post_type' )->justReturn( 'vl_quiz_question' );

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

	public function test_save_decodes_and_writes_answers_json(): void {
		$payload = json_encode(
			[ // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			[
				'id'          => 'aaaa-1',
				'text'        => 'Перша відповідь',
				'is_correct'  => true,
				'explanation' => 'Бо так',
			],
			[
				'id'          => 'aaaa-2',
				'text'        => 'Друга',
				'is_correct'  => false,
				'explanation' => '',
			],
			]
		);

		$_POST = [
			self::NONCE_FIELD           => 'nonce-x',
			'_vl_question_answers_json' => $payload,
		];

		$post = Mockery::mock( 'WP_Post' );
		assert( $post instanceof WP_Post );
		( new QuizQuestionMetaBox() )->save( 21, $post );

		$answer_writes = array_values(
			array_filter(
				$this->writes,
				static fn ( array $row ): bool => '_vl_question_answers' === $row[1]
			)
		);
		self::assertCount( 1, $answer_writes );
		$decoded = json_decode( (string) $answer_writes[0][2], true );
		self::assertIsArray( $decoded );
		self::assertCount( 2, $decoded );
		self::assertSame( 'aaaa-1', $decoded[0]['id'] );
		self::assertSame( 'Перша відповідь', $decoded[0]['text'] );
		self::assertTrue( $decoded[0]['is_correct'] );
		self::assertFalse( $decoded[1]['is_correct'] );
	}

	public function test_save_binds_question_to_parent_quiz(): void {
		Functions\when( 'get_post_type' )->alias(
			static fn ( int $id ): string => 500 === $id ? 'vl_quiz' : 'vl_quiz_question'
		);
		Functions\when( 'get_post_field' )->justReturn( 0 );

		$captured = null;
		Functions\when( 'wp_update_post' )->alias(
			static function ( array $arr ) use ( &$captured ): int {
				$captured = $arr;
				return (int) $arr['ID'];
			}
		);

		$_POST = [
			self::NONCE_FIELD      => 'nonce-x',
			'_vl_question_quiz_id' => '500',
		];

		$post = Mockery::mock( 'WP_Post' );
		assert( $post instanceof WP_Post );
		( new QuizQuestionMetaBox() )->save( 21, $post );

		self::assertSame(
			[
				'ID'          => 21,
				'post_parent' => 500,
			],
			$captured
		);
	}

	public function test_save_does_not_rebind_when_parent_unchanged(): void {
		Functions\when( 'get_post_type' )->alias(
			static fn ( int $id ): string => 500 === $id ? 'vl_quiz' : 'vl_quiz_question'
		);
		Functions\when( 'get_post_field' )->justReturn( 500 );

		$called = false;
		Functions\when( 'wp_update_post' )->alias(
			static function () use ( &$called ): int {
				$called = true;
				return 0;
			}
		);

		$_POST = [
			self::NONCE_FIELD      => 'nonce-x',
			'_vl_question_quiz_id' => '500',
		];

		$post = Mockery::mock( 'WP_Post' );
		assert( $post instanceof WP_Post );
		( new QuizQuestionMetaBox() )->save( 21, $post );

		self::assertFalse( $called );
	}
}
