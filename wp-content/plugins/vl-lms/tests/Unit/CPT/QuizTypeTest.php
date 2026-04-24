<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\CPT;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use VL\LMS\CPT\QuizType;

final class QuizTypeTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Keys (and only these keys) that the QuizType registers as meta.
	 *
	 * @var list<string>
	 */
	private const array EXPECTED_META_KEYS = [
		'_vl_quiz_time_limit_seconds',
		'_vl_quiz_max_attempts',
		'_vl_quiz_passing_threshold',
		'_vl_quiz_shuffle_questions',
		'_vl_quiz_shuffle_answers',
		'_vl_quiz_show_correct_answers',
		'_vl_quiz_is_final_exam',
	];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg( 1 );
		// String callables in meta_fields() reference WP functions that are
		// not loaded in unit tests. Declare stubs so is_callable() returns true.
		Functions\when( 'absint' )->returnArg( 1 );
		Functions\when( 'rest_sanitize_boolean' )->returnArg( 1 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_post_type_returns_vl_quiz(): void {
		self::assertSame( 'vl_quiz', $this->invoke_protected( 'post_type' ) );
	}

	public function test_capability_type_pair(): void {
		self::assertSame( [ 'vl_quiz', 'vl_quizzes' ], $this->invoke_protected( 'capability_type' ) );
	}

	public function test_supports_contains_exactly_required_features(): void {
		self::assertSame(
			[ 'title', 'editor', 'custom-fields', 'page-attributes' ],
			$this->invoke_protected( 'supports' )
		);
	}

	public function test_menu_icon_is_dashicons_clipboard(): void {
		self::assertSame( 'dashicons-clipboard', $this->invoke_protected( 'menu_icon' ) );
	}

	public function test_hierarchical_returns_false(): void {
		self::assertFalse( $this->invoke_protected( 'hierarchical' ) );
	}

	public function test_show_in_menu_returns_true(): void {
		self::assertTrue( $this->invoke_protected( 'show_in_menu' ) );
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

		self::assertSame( 0, $fields['_vl_quiz_time_limit_seconds']['default'] );
		self::assertSame( 0, $fields['_vl_quiz_max_attempts']['default'] );
		self::assertSame( 80, $fields['_vl_quiz_passing_threshold']['default'] );
		self::assertFalse( $fields['_vl_quiz_shuffle_questions']['default'] );
		self::assertFalse( $fields['_vl_quiz_shuffle_answers']['default'] );
		self::assertSame( 'after_submit', $fields['_vl_quiz_show_correct_answers']['default'] );
		self::assertFalse( $fields['_vl_quiz_is_final_exam']['default'] );
	}

	public function test_sanitize_percent(): void {
		self::assertSame( 50, $this->invoke_sanitizer( 'sanitize_percent', 50 ) );
		self::assertSame( 0, $this->invoke_sanitizer( 'sanitize_percent', 0 ) );
		self::assertSame( 100, $this->invoke_sanitizer( 'sanitize_percent', 100 ) );
		self::assertSame( 0, $this->invoke_sanitizer( 'sanitize_percent', -10 ) );
		self::assertSame( 100, $this->invoke_sanitizer( 'sanitize_percent', 150 ) );
		self::assertSame( 0, $this->invoke_sanitizer( 'sanitize_percent', 'abc' ) );
		self::assertSame( 75, $this->invoke_sanitizer( 'sanitize_percent', '75' ) );
	}

	public function test_sanitize_show_correct_answers(): void {
		self::assertSame( 'never', $this->invoke_sanitizer( 'sanitize_show_correct_answers', 'never' ) );
		self::assertSame( 'after_submit', $this->invoke_sanitizer( 'sanitize_show_correct_answers', 'after_submit' ) );
		self::assertSame( 'after_pass', $this->invoke_sanitizer( 'sanitize_show_correct_answers', 'after_pass' ) );
		self::assertSame( 'after_submit', $this->invoke_sanitizer( 'sanitize_show_correct_answers', 'immediately' ) );
		self::assertSame( 'after_submit', $this->invoke_sanitizer( 'sanitize_show_correct_answers', '' ) );
		self::assertSame( 'after_submit', $this->invoke_sanitizer( 'sanitize_show_correct_answers', 42 ) );
	}

	private function invoke_protected( string $method ): mixed {
		$reflection = new ReflectionMethod( QuizType::class, $method );
		return $reflection->invoke( new QuizType() );
	}

	private function invoke_sanitizer( string $method, mixed $value ): mixed {
		$reflection = new ReflectionMethod( QuizType::class, $method );
		return $reflection->invoke( null, $value );
	}
}
