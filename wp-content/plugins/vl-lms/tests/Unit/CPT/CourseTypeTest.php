<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\CPT;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use VL\LMS\CPT\CourseType;

final class CourseTypeTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Keys (and only these keys) that the CourseType registers as meta.
	 *
	 * @var list<string>
	 */
	private const array EXPECTED_META_KEYS = [
		'_vl_course_type',
		'_vl_course_price',
		'_vl_course_currency',
		'_vl_course_duration_hours',
		'_vl_course_enrollment_open',
		'_vl_course_enrollment_opens_at',
		'_vl_course_enrollment_closes_at',
		'_vl_course_starts_at',
		'_vl_course_ends_at',
		'_vl_course_max_students',
		'_vl_course_preview_video_url',
		'_vl_course_certificate_enabled',
		'_vl_course_passing_threshold',
	];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg( 1 );
		// String callables in meta_fields() reference WP functions that
		// are not loaded in unit tests — declare stubs so is_callable()
		// returns true.
		Functions\when( 'absint' )->returnArg( 1 );
		Functions\when( 'rest_sanitize_boolean' )->returnArg( 1 );
		Functions\when( 'esc_url_raw' )->returnArg( 1 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_post_type_returns_vl_course(): void {
		self::assertSame( 'vl_course', $this->invoke_protected( 'post_type' ) );
	}

	public function test_capability_type_pair(): void {
		self::assertSame( [ 'vl_course', 'vl_courses' ], $this->invoke_protected( 'capability_type' ) );
	}

	public function test_supports_includes_required_features(): void {
		$supports = $this->invoke_protected( 'supports' );

		self::assertContains( 'title', $supports );
		self::assertContains( 'editor', $supports );
		self::assertContains( 'author', $supports );
		self::assertContains( 'thumbnail', $supports );
	}

	public function test_menu_icon_is_dashicons_welcome_learn_more(): void {
		self::assertSame( 'dashicons-welcome-learn-more', $this->invoke_protected( 'menu_icon' ) );
	}

	public function test_meta_fields_contain_exactly_thirteen_documented_keys(): void {
		$fields = $this->invoke_protected( 'meta_fields' );

		self::assertSame( self::EXPECTED_META_KEYS, array_keys( $fields ) );
		self::assertCount( 13, $fields );
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

		self::assertSame( 'self_paced', $fields['_vl_course_type']['default'] );
		self::assertSame( 0, $fields['_vl_course_price']['default'] );
		self::assertSame( 'UAH', $fields['_vl_course_currency']['default'] );
		self::assertSame( 0.0, $fields['_vl_course_duration_hours']['default'] );
		self::assertTrue( $fields['_vl_course_enrollment_open']['default'] );
		self::assertSame( '', $fields['_vl_course_enrollment_opens_at']['default'] );
		self::assertSame( '', $fields['_vl_course_enrollment_closes_at']['default'] );
		self::assertSame( '', $fields['_vl_course_starts_at']['default'] );
		self::assertSame( '', $fields['_vl_course_ends_at']['default'] );
		self::assertSame( 0, $fields['_vl_course_max_students']['default'] );
		self::assertSame( '', $fields['_vl_course_preview_video_url']['default'] );
		self::assertFalse( $fields['_vl_course_certificate_enabled']['default'] );
		self::assertSame( 80, $fields['_vl_course_passing_threshold']['default'] );
	}

	public function test_sanitize_course_type(): void {
		self::assertSame( 'self_paced', $this->invoke_sanitizer( 'sanitize_course_type', 'self_paced' ) );
		self::assertSame( 'cohort', $this->invoke_sanitizer( 'sanitize_course_type', 'cohort' ) );
		self::assertSame( 'self_paced', $this->invoke_sanitizer( 'sanitize_course_type', 'garbage' ) );
		self::assertSame( 'self_paced', $this->invoke_sanitizer( 'sanitize_course_type', '' ) );
		self::assertSame( 'self_paced', $this->invoke_sanitizer( 'sanitize_course_type', 42 ) );
	}

	public function test_sanitize_currency_code(): void {
		self::assertSame( 'USD', $this->invoke_sanitizer( 'sanitize_currency_code', 'usd' ) );
		self::assertSame( 'UAH', $this->invoke_sanitizer( 'sanitize_currency_code', 'UAH' ) );
		self::assertSame( 'UAH', $this->invoke_sanitizer( 'sanitize_currency_code', 'TOOLONG' ) );
		self::assertSame( 'UAH', $this->invoke_sanitizer( 'sanitize_currency_code', '12' ) );
		self::assertSame( 'UAH', $this->invoke_sanitizer( 'sanitize_currency_code', '' ) );
		self::assertSame( 'UAH', $this->invoke_sanitizer( 'sanitize_currency_code', null ) );
	}

	public function test_sanitize_iso8601(): void {
		self::assertSame(
			'2026-05-01T10:00:00Z',
			$this->invoke_sanitizer( 'sanitize_iso8601', '2026-05-01T10:00:00Z' )
		);
		self::assertSame(
			'2026-05-01T10:00:00+02:00',
			$this->invoke_sanitizer( 'sanitize_iso8601', '2026-05-01T10:00:00+02:00' )
		);
		self::assertSame( '', $this->invoke_sanitizer( 'sanitize_iso8601', 'not-a-date' ) );
		self::assertSame( '', $this->invoke_sanitizer( 'sanitize_iso8601', '' ) );
		self::assertSame( '', $this->invoke_sanitizer( 'sanitize_iso8601', '2026-05-01' ) );
	}

	public function test_sanitize_percent(): void {
		self::assertSame( 50, $this->invoke_sanitizer( 'sanitize_percent', 50 ) );
		self::assertSame( 0, $this->invoke_sanitizer( 'sanitize_percent', -10 ) );
		self::assertSame( 100, $this->invoke_sanitizer( 'sanitize_percent', 150 ) );
		self::assertSame( 0, $this->invoke_sanitizer( 'sanitize_percent', 'abc' ) );
		self::assertSame( 75, $this->invoke_sanitizer( 'sanitize_percent', '75' ) );
		self::assertSame( 0, $this->invoke_sanitizer( 'sanitize_percent', null ) );
	}

	public function test_sanitize_positive_float(): void {
		self::assertSame( 1.5, $this->invoke_sanitizer( 'sanitize_positive_float', 1.5 ) );
		self::assertSame( 0.0, $this->invoke_sanitizer( 'sanitize_positive_float', -2.0 ) );
		self::assertSame( 0.0, $this->invoke_sanitizer( 'sanitize_positive_float', 'abc' ) );
		self::assertSame( 3.0, $this->invoke_sanitizer( 'sanitize_positive_float', '3' ) );
	}

	private function invoke_protected( string $method ): mixed {
		$reflection = new ReflectionMethod( CourseType::class, $method );
		return $reflection->invoke( new CourseType() );
	}

	private function invoke_sanitizer( string $method, mixed $value ): mixed {
		$reflection = new ReflectionMethod( CourseType::class, $method );
		return $reflection->invoke( null, $value );
	}
}
