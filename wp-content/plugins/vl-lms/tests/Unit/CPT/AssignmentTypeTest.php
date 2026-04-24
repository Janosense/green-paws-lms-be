<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\CPT;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use VL\LMS\CPT\AssignmentType;

final class AssignmentTypeTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Keys (and only these keys) that the AssignmentType registers as meta.
	 *
	 * @var list<string>
	 */
	private const array EXPECTED_META_KEYS = [
		'_vl_assignment_max_score',
		'_vl_assignment_passing_score',
		'_vl_assignment_submission_type',
		'_vl_assignment_text_required',
		'_vl_assignment_file_required',
		'_vl_assignment_due_days_after_enrollment',
		'_vl_assignment_rubric',
	];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg( 1 );
		// String callables in meta_fields() reference WP functions that are
		// not loaded in unit tests. Declare stubs so is_callable() returns true.
		Functions\when( 'absint' )->returnArg( 1 );
		Functions\when( 'rest_sanitize_boolean' )->returnArg( 1 );
		Functions\when( 'wp_kses_post' )->returnArg( 1 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_post_type_returns_vl_assignment(): void {
		self::assertSame( 'vl_assignment', $this->invoke_protected( 'post_type' ) );
	}

	public function test_capability_type_pair(): void {
		self::assertSame(
			[ 'vl_assignment', 'vl_assignments' ],
			$this->invoke_protected( 'capability_type' )
		);
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

		self::assertSame( 100, $fields['_vl_assignment_max_score']['default'] );
		self::assertSame( 60, $fields['_vl_assignment_passing_score']['default'] );
		self::assertSame( 'both', $fields['_vl_assignment_submission_type']['default'] );
		self::assertTrue( $fields['_vl_assignment_text_required']['default'] );
		self::assertFalse( $fields['_vl_assignment_file_required']['default'] );
		self::assertSame( 0, $fields['_vl_assignment_due_days_after_enrollment']['default'] );
		self::assertSame( '', $fields['_vl_assignment_rubric']['default'] );
	}

	public function test_sanitize_submission_type(): void {
		self::assertSame( 'text', $this->invoke_sanitizer( 'sanitize_submission_type', 'text' ) );
		self::assertSame( 'file', $this->invoke_sanitizer( 'sanitize_submission_type', 'file' ) );
		self::assertSame( 'both', $this->invoke_sanitizer( 'sanitize_submission_type', 'both' ) );
		self::assertSame( 'both', $this->invoke_sanitizer( 'sanitize_submission_type', 'video' ) );
		self::assertSame( 'both', $this->invoke_sanitizer( 'sanitize_submission_type', '' ) );
		self::assertSame( 'both', $this->invoke_sanitizer( 'sanitize_submission_type', 42 ) );
	}

	private function invoke_protected( string $method ): mixed {
		$reflection = new ReflectionMethod( AssignmentType::class, $method );
		return $reflection->invoke( new AssignmentType() );
	}

	private function invoke_sanitizer( string $method, mixed $value ): mixed {
		$reflection = new ReflectionMethod( AssignmentType::class, $method );
		return $reflection->invoke( null, $value );
	}
}
