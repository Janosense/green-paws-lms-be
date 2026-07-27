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

	public function test_save_decodes_and_writes_answers_as_array(): void {
		$payload = json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			[
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
		// The registered sanitize callback (QuizQuestionType::sanitize_answers)
		// collapses any non-array write to [] — the value must be an array.
		$written = $answer_writes[0][2];
		self::assertIsArray( $written );
		self::assertCount( 2, $written );
		self::assertSame( 'aaaa-1', $written[0]['id'] );
		self::assertSame( 'Перша відповідь', $written[0]['text'] );
		self::assertTrue( $written[0]['is_correct'] );
		self::assertFalse( $written[1]['is_correct'] );
	}

	public function test_save_fills_missing_answer_id_with_uuid(): void {
		Functions\when( 'wp_generate_uuid4' )->justReturn( 'generated-uuid' );

		$payload = json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			[
				[
					'id'          => '',
					'text'        => 'Без ідентифікатора',
					'is_correct'  => true,
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
		self::assertSame( 'generated-uuid', $answer_writes[0][2][0]['id'] );
	}

	public function test_save_drops_textless_incorrect_answers(): void {
		$payload = json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			[
				[
					'id'          => 'aaaa-1',
					'text'        => '',
					'is_correct'  => false,
					'explanation' => 'порожній рядок з віджета',
				],
				[
					'id'          => 'aaaa-2',
					'text'        => 'Справжня відповідь',
					'is_correct'  => true,
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
		self::assertCount( 1, $answer_writes[0][2] );
		self::assertSame( 'aaaa-2', $answer_writes[0][2][0]['id'] );
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
