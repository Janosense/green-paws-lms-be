<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\CPT;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use VL\LMS\CPT\QuizQuestionType;

final class QuizQuestionTypeTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const string STUB_UUID = 'uuid-11111111-2222-4333-8444-555555555555';

	/**
	 * Keys (and only these keys) that the QuizQuestionType registers as meta.
	 *
	 * @var list<string>
	 */
	private const array EXPECTED_META_KEYS = [
		'_vl_question_type',
		'_vl_question_points',
		'_vl_question_answers',
		'_vl_question_correct_text',
		'_vl_question_match_mode',
		'_vl_question_explanation',
		'_vl_question_media_url',
	];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg( 1 );
		// String callables in meta_fields() — and the helpers used inside
		// sanitize_answers — reference WP functions that are not loaded
		// in unit tests. Declare stubs so is_callable() returns true and
		// sanitize_answers can run without touching WP core.
		Functions\when( 'absint' )->returnArg( 1 );
		Functions\when( 'esc_url_raw' )->returnArg( 1 );
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'wp_kses_post' )->returnArg( 1 );
		Functions\when( 'rest_sanitize_boolean' )
			->alias( static fn ( mixed $v ): bool => filter_var( $v, FILTER_VALIDATE_BOOLEAN ) );
		Functions\when( 'wp_generate_uuid4' )->alias( static fn (): string => self::STUB_UUID );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_post_type_returns_vl_quiz_question(): void {
		self::assertSame( 'vl_quiz_question', $this->invoke_protected( 'post_type' ) );
	}

	public function test_capability_type_pair(): void {
		self::assertSame(
			[ 'vl_quiz_question', 'vl_quiz_questions' ],
			$this->invoke_protected( 'capability_type' )
		);
	}

	public function test_supports_contains_exactly_required_features(): void {
		self::assertSame(
			[ 'title', 'editor', 'custom-fields', 'page-attributes' ],
			$this->invoke_protected( 'supports' )
		);
	}

	public function test_menu_icon_is_null(): void {
		self::assertNull( $this->invoke_protected( 'menu_icon' ) );
	}

	public function test_hierarchical_returns_false(): void {
		self::assertFalse( $this->invoke_protected( 'hierarchical' ) );
	}

	public function test_show_in_menu_returns_false(): void {
		self::assertFalse( $this->invoke_protected( 'show_in_menu' ) );
	}

	public function test_meta_fields_contain_exactly_seven_documented_keys(): void {
		$fields = $this->invoke_protected( 'meta_fields' );

		self::assertSame( self::EXPECTED_META_KEYS, array_keys( $fields ) );
		self::assertCount( 7, $fields );
	}

	public function test_every_meta_field_is_single_with_show_in_rest_false_and_callable_sanitizer(): void {
		$fields = $this->invoke_protected( 'meta_fields' );

		foreach ( $fields as $key => $args ) {
			self::assertFalse( $args['show_in_rest'], "{$key} must have show_in_rest => false" );
			self::assertTrue( $args['single'], "{$key} must be single" );
			self::assertArrayHasKey( 'default', $args, "{$key} must declare a default" );
			self::assertIsCallable( $args['sanitize_callback'], "{$key} sanitize_callback must be callable" );
			self::assertIsCallable( $args['auth_callback'], "{$key} auth_callback must be callable" );
		}
	}

	public function test_meta_field_defaults_match_documented_types(): void {
		$fields = $this->invoke_protected( 'meta_fields' );

		self::assertSame( 'single_choice', $fields['_vl_question_type']['default'] );
		self::assertSame( 1, $fields['_vl_question_points']['default'] );
		self::assertSame( [], $fields['_vl_question_answers']['default'] );
		self::assertSame( '', $fields['_vl_question_correct_text']['default'] );
		self::assertSame( 'exact', $fields['_vl_question_match_mode']['default'] );
		self::assertSame( '', $fields['_vl_question_explanation']['default'] );
		self::assertSame( '', $fields['_vl_question_media_url']['default'] );
	}

	public function test_sanitize_question_type(): void {
		self::assertSame( 'single_choice', $this->invoke_sanitizer( 'sanitize_question_type', 'single_choice' ) );
		self::assertSame( 'multiple_choice', $this->invoke_sanitizer( 'sanitize_question_type', 'multiple_choice' ) );
		self::assertSame( 'true_false', $this->invoke_sanitizer( 'sanitize_question_type', 'true_false' ) );
		self::assertSame( 'text', $this->invoke_sanitizer( 'sanitize_question_type', 'text' ) );
		self::assertSame( 'single_choice', $this->invoke_sanitizer( 'sanitize_question_type', 'essay' ) );
		self::assertSame( 'single_choice', $this->invoke_sanitizer( 'sanitize_question_type', '' ) );
		self::assertSame( 'single_choice', $this->invoke_sanitizer( 'sanitize_question_type', 42 ) );
	}

	public function test_sanitize_match_mode(): void {
		self::assertSame( 'exact', $this->invoke_sanitizer( 'sanitize_match_mode', 'exact' ) );
		self::assertSame( 'case_insensitive', $this->invoke_sanitizer( 'sanitize_match_mode', 'case_insensitive' ) );
		self::assertSame( 'regex', $this->invoke_sanitizer( 'sanitize_match_mode', 'regex' ) );
		self::assertSame( 'exact', $this->invoke_sanitizer( 'sanitize_match_mode', 'partial' ) );
		self::assertSame( 'exact', $this->invoke_sanitizer( 'sanitize_match_mode', '' ) );
		self::assertSame( 'exact', $this->invoke_sanitizer( 'sanitize_match_mode', 7 ) );
	}

	public function test_sanitize_answers_rejects_non_array_input(): void {
		self::assertSame( [], $this->invoke_sanitizer( 'sanitize_answers', 'string' ) );
		self::assertSame( [], $this->invoke_sanitizer( 'sanitize_answers', null ) );
		self::assertSame( [], $this->invoke_sanitizer( 'sanitize_answers', 42 ) );
	}

	public function test_sanitize_answers_returns_empty_for_empty_array(): void {
		self::assertSame( [], $this->invoke_sanitizer( 'sanitize_answers', [] ) );
	}

	public function test_sanitize_answers_preserves_valid_element_and_defaults_explanation(): void {
		$result = $this->invoke_sanitizer(
			'sanitize_answers',
			[
				[
					'id'         => 'a1',
					'text'       => 'Option A',
					'is_correct' => true,
				],
			]
		);

		self::assertSame(
			[
				[
					'id'          => 'a1',
					'text'        => 'Option A',
					'is_correct'  => true,
					'explanation' => '',
				],
			],
			$result
		);
	}

	public function test_sanitize_answers_generates_uuid_when_id_missing(): void {
		$result = $this->invoke_sanitizer(
			'sanitize_answers',
			[
				[
					'text'       => 'Option A',
					'is_correct' => true,
				],
			]
		);

		self::assertCount( 1, $result );
		self::assertIsString( $result[0]['id'] );
		self::assertNotSame( '', $result[0]['id'] );
		self::assertSame( self::STUB_UUID, $result[0]['id'] );
	}

	public function test_sanitize_answers_keeps_textless_correct_answer(): void {
		$result = $this->invoke_sanitizer(
			'sanitize_answers',
			[
				[
					'id'         => 'tf_true',
					'is_correct' => true,
				],
			]
		);

		self::assertSame(
			[
				[
					'id'          => 'tf_true',
					'text'        => '',
					'is_correct'  => true,
					'explanation' => '',
				],
			],
			$result
		);
	}

	public function test_sanitize_answers_drops_textless_incorrect_answer(): void {
		$result = $this->invoke_sanitizer(
			'sanitize_answers',
			[
				[
					'id'         => 'x',
					'text'       => '',
					'is_correct' => false,
				],
			]
		);

		self::assertSame( [], $result );
	}

	public function test_sanitize_answers_coerces_is_correct_string_one_to_true(): void {
		$result = $this->invoke_sanitizer(
			'sanitize_answers',
			[
				[
					'id'         => 'a1',
					'text'       => 'Option A',
					'is_correct' => '1',
				],
			]
		);

		self::assertCount( 1, $result );
		self::assertTrue( $result[0]['is_correct'] );
	}

	public function test_sanitize_answers_coerces_is_correct_string_zero_to_false(): void {
		$result = $this->invoke_sanitizer(
			'sanitize_answers',
			[
				[
					'id'         => 'a1',
					'text'       => 'Option A',
					'is_correct' => '0',
				],
			]
		);

		self::assertCount( 1, $result );
		self::assertFalse( $result[0]['is_correct'] );
	}

	public function test_sanitize_answers_strips_unexpected_keys(): void {
		$result = $this->invoke_sanitizer(
			'sanitize_answers',
			[
				[
					'id'         => 'a1',
					'text'       => 'Option A',
					'is_correct' => true,
					'weight'     => 99,
					'evil'       => '<script>',
				],
			]
		);

		self::assertCount( 1, $result );
		self::assertSame(
			[ 'id', 'text', 'is_correct', 'explanation' ],
			array_keys( $result[0] )
		);
	}

	public function test_sanitize_answers_drops_non_array_elements(): void {
		$result = $this->invoke_sanitizer(
			'sanitize_answers',
			[
				'not-an-array',
				42,
				null,
				[
					'id'         => 'a1',
					'text'       => 'Option A',
					'is_correct' => true,
				],
			]
		);

		self::assertCount( 1, $result );
		self::assertSame( 'a1', $result[0]['id'] );
	}

	public function test_sanitize_answers_filters_and_reindexes_mixed_input(): void {
		$result = $this->invoke_sanitizer(
			'sanitize_answers',
			[
				'not-an-array',
				[
					'id'         => 'drop',
					'text'       => '',
					'is_correct' => false,
				],
				[
					'id'         => 'keep',
					'text'       => 'Keeper',
					'is_correct' => true,
				],
			]
		);

		self::assertSame(
			[
				[
					'id'          => 'keep',
					'text'        => 'Keeper',
					'is_correct'  => true,
					'explanation' => '',
				],
			],
			$result
		);
		self::assertSame( [ 0 ], array_keys( $result ) );
	}

	private function invoke_protected( string $method ): mixed {
		$reflection = new ReflectionMethod( QuizQuestionType::class, $method );
		return $reflection->invoke( new QuizQuestionType() );
	}

	private function invoke_sanitizer( string $method, mixed $value ): mixed {
		$reflection = new ReflectionMethod( QuizQuestionType::class, $method );
		return $reflection->invoke( null, $value );
	}
}
