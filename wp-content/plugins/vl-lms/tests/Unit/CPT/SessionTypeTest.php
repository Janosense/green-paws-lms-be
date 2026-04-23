<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\CPT;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use VL\LMS\CPT\SessionType;

final class SessionTypeTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Keys (and only these keys) that the SessionType registers as meta.
	 *
	 * @var list<string>
	 */
	private const EXPECTED_META_KEYS = [
		'_vl_session_number',
		'_vl_session_scheduled_start',
		'_vl_session_scheduled_end',
		'_vl_session_status',
		'_vl_session_zoom_meeting_id',
		'_vl_session_zoom_join_url',
		'_vl_session_zoom_start_url',
		'_vl_session_zoom_password',
		'_vl_session_recording_url',
		'_vl_session_recording_available_until',
		'_vl_session_materials',
	];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg( 1 );
		// String callables in meta_fields() — and the helpers used inside
		// sanitize_materials — reference WP functions that are not loaded
		// in unit tests. Declare stubs so is_callable() returns true and
		// sanitize_materials can run without touching WP core.
		Functions\when( 'absint' )->alias( static fn ( mixed $v ): int => (int) $v );
		Functions\when( 'esc_url_raw' )->returnArg( 1 );
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_post_type_returns_vl_session(): void {
		self::assertSame( 'vl_session', $this->invoke_protected( 'post_type' ) );
	}

	public function test_capability_type_pair(): void {
		self::assertSame( [ 'vl_session', 'vl_sessions' ], $this->invoke_protected( 'capability_type' ) );
	}

	public function test_supports_contains_exactly_required_features(): void {
		self::assertSame(
			[ 'title', 'editor', 'custom-fields', 'page-attributes' ],
			$this->invoke_protected( 'supports' )
		);
	}

	public function test_menu_icon_is_dashicons_calendar_alt(): void {
		self::assertSame( 'dashicons-calendar-alt', $this->invoke_protected( 'menu_icon' ) );
	}

	public function test_hierarchical_returns_false(): void {
		self::assertFalse( $this->invoke_protected( 'hierarchical' ) );
	}

	public function test_show_in_menu_returns_true(): void {
		self::assertTrue( $this->invoke_protected( 'show_in_menu' ) );
	}

	public function test_meta_fields_contain_exactly_eleven_documented_keys(): void {
		$fields = $this->invoke_protected( 'meta_fields' );

		self::assertSame( self::EXPECTED_META_KEYS, array_keys( $fields ) );
		self::assertCount( 11, $fields );
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

		self::assertSame( 0, $fields['_vl_session_number']['default'] );
		self::assertSame( '', $fields['_vl_session_scheduled_start']['default'] );
		self::assertSame( '', $fields['_vl_session_scheduled_end']['default'] );
		self::assertSame( 'scheduled', $fields['_vl_session_status']['default'] );
		self::assertSame( '', $fields['_vl_session_zoom_meeting_id']['default'] );
		self::assertSame( '', $fields['_vl_session_zoom_join_url']['default'] );
		self::assertSame( '', $fields['_vl_session_zoom_start_url']['default'] );
		self::assertSame( '', $fields['_vl_session_zoom_password']['default'] );
		self::assertSame( '', $fields['_vl_session_recording_url']['default'] );
		self::assertSame( '', $fields['_vl_session_recording_available_until']['default'] );
		self::assertSame( [], $fields['_vl_session_materials']['default'] );
	}

	public function test_sanitize_iso8601(): void {
		self::assertSame( '2026-05-01T10:00:00Z', $this->invoke_sanitizer( 'sanitize_iso8601', '2026-05-01T10:00:00Z' ) );
		self::assertSame( '2026-05-01T10:00:00+02:00', $this->invoke_sanitizer( 'sanitize_iso8601', '2026-05-01T10:00:00+02:00' ) );
		self::assertSame( '', $this->invoke_sanitizer( 'sanitize_iso8601', 'not-a-date' ) );
		self::assertSame( '', $this->invoke_sanitizer( 'sanitize_iso8601', '' ) );
		self::assertSame( '', $this->invoke_sanitizer( 'sanitize_iso8601', null ) );
		self::assertSame( '', $this->invoke_sanitizer( 'sanitize_iso8601', 12345 ) );
	}

	public function test_sanitize_status(): void {
		self::assertSame( 'scheduled', $this->invoke_sanitizer( 'sanitize_status', 'scheduled' ) );
		self::assertSame( 'live', $this->invoke_sanitizer( 'sanitize_status', 'live' ) );
		self::assertSame( 'completed', $this->invoke_sanitizer( 'sanitize_status', 'completed' ) );
		self::assertSame( 'cancelled', $this->invoke_sanitizer( 'sanitize_status', 'cancelled' ) );
		self::assertSame( 'scheduled', $this->invoke_sanitizer( 'sanitize_status', 'pending' ) );
		self::assertSame( 'scheduled', $this->invoke_sanitizer( 'sanitize_status', '' ) );
		self::assertSame( 'scheduled', $this->invoke_sanitizer( 'sanitize_status', 42 ) );
	}

	public function test_sanitize_materials_rejects_non_array_input(): void {
		self::assertSame( [], $this->invoke_sanitizer( 'sanitize_materials', 'string' ) );
		self::assertSame( [], $this->invoke_sanitizer( 'sanitize_materials', null ) );
		self::assertSame( [], $this->invoke_sanitizer( 'sanitize_materials', 42 ) );
	}

	public function test_sanitize_materials_returns_empty_for_empty_array(): void {
		self::assertSame( [], $this->invoke_sanitizer( 'sanitize_materials', [] ) );
	}

	public function test_sanitize_materials_preserves_valid_element(): void {
		$result = $this->invoke_sanitizer(
			'sanitize_materials',
			[
				[
					'url'  => 'https://example.com/a.pdf',
					'name' => 'A',
					'size' => 100,
				],
			]
		);

		self::assertSame(
			[
				[
					'url'  => 'https://example.com/a.pdf',
					'name' => 'A',
					'size' => 100,
				],
			],
			$result
		);
	}

	public function test_sanitize_materials_defaults_missing_name(): void {
		$result = $this->invoke_sanitizer(
			'sanitize_materials',
			[
				[
					'url'  => 'https://example.com/a.pdf',
					'size' => 100,
				],
			]
		);

		self::assertSame(
			[
				[
					'url'  => 'https://example.com/a.pdf',
					'name' => '',
					'size' => 100,
				],
			],
			$result
		);
	}

	public function test_sanitize_materials_defaults_missing_size(): void {
		$result = $this->invoke_sanitizer(
			'sanitize_materials',
			[
				[
					'url'  => 'https://example.com/a.pdf',
					'name' => 'A',
				],
			]
		);

		self::assertSame(
			[
				[
					'url'  => 'https://example.com/a.pdf',
					'name' => 'A',
					'size' => 0,
				],
			],
			$result
		);
	}

	public function test_sanitize_materials_drops_elements_with_empty_url(): void {
		$result = $this->invoke_sanitizer(
			'sanitize_materials',
			[
				[
					'url'  => '',
					'name' => 'Empty',
					'size' => 1,
				],
			]
		);

		self::assertSame( [], $result );
	}

	public function test_sanitize_materials_strips_unexpected_keys(): void {
		$result = $this->invoke_sanitizer(
			'sanitize_materials',
			[
				[
					'url'  => 'https://example.com/a.pdf',
					'name' => 'A',
					'size' => 100,
					'evil' => '<script>',
					'mime' => 'application/pdf',
				],
			]
		);

		self::assertCount( 1, $result );
		self::assertSame(
			[ 'url', 'name', 'size' ],
			array_keys( $result[0] )
		);
	}

	public function test_sanitize_materials_filters_and_reindexes_mixed_input(): void {
		$result = $this->invoke_sanitizer(
			'sanitize_materials',
			[
				'not-an-array',
				[
					'url'  => '',
					'name' => 'dropped',
				],
				[
					'url'  => 'https://example.com/keeper.pdf',
					'name' => 'keeper',
					'size' => 5,
				],
			]
		);

		self::assertSame(
			[
				[
					'url'  => 'https://example.com/keeper.pdf',
					'name' => 'keeper',
					'size' => 5,
				],
			],
			$result
		);
		self::assertSame( [ 0 ], array_keys( $result ) );
	}

	private function invoke_protected( string $method ): mixed {
		$reflection = new ReflectionMethod( SessionType::class, $method );
		return $reflection->invoke( new SessionType() );
	}

	private function invoke_sanitizer( string $method, mixed $value ): mixed {
		$reflection = new ReflectionMethod( SessionType::class, $method );
		return $reflection->invoke( null, $value );
	}
}
