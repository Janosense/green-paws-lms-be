<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\CPT;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use VL\LMS\CPT\WebinarType;

final class WebinarTypeTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Keys (and only these keys) that the WebinarType registers as meta.
	 *
	 * @var list<string>
	 */
	private const array EXPECTED_META_KEYS = [
		'_vl_webinar_scheduled_start',
		'_vl_webinar_scheduled_end',
		'_vl_webinar_status',
		'_vl_webinar_price',
		'_vl_webinar_cover_image_id',
		'_vl_webinar_currency',
		'_vl_webinar_max_attendees',
		'_vl_webinar_registration_opens_at',
		'_vl_webinar_registration_closes_at',
		'_vl_webinar_zoom_meeting_id',
		'_vl_webinar_zoom_join_url',
		'_vl_webinar_zoom_start_url',
		'_vl_webinar_zoom_password',
		'_vl_webinar_recording_url',
		'_vl_webinar_recording_access_days',
		'_vl_webinar_preview_video_url',
	];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg( 1 );
		// String callables in meta_fields() reference WP functions that are
		// not loaded in unit tests. Declare stubs so is_callable() returns true.
		Functions\when( 'absint' )->returnArg( 1 );
		Functions\when( 'esc_url_raw' )->returnArg( 1 );
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_post_type_returns_vl_webinar(): void {
		self::assertSame( 'vl_webinar', $this->invoke_protected( 'post_type' ) );
	}

	public function test_capability_type_pair(): void {
		self::assertSame( [ 'vl_webinar', 'vl_webinars' ], $this->invoke_protected( 'capability_type' ) );
	}

	public function test_supports_contains_exactly_required_features(): void {
		self::assertSame(
			[ 'title', 'editor', 'excerpt', 'thumbnail', 'author', 'custom-fields' ],
			$this->invoke_protected( 'supports' )
		);
	}

	public function test_supports_omits_page_attributes(): void {
		self::assertNotContains( 'page-attributes', $this->invoke_protected( 'supports' ) );
	}

	public function test_menu_icon_is_dashicons_video_alt3(): void {
		self::assertSame( 'dashicons-video-alt3', $this->invoke_protected( 'menu_icon' ) );
	}

	public function test_hierarchical_returns_false(): void {
		self::assertFalse( $this->invoke_protected( 'hierarchical' ) );
	}

	public function test_show_in_menu_returns_true(): void {
		self::assertTrue( $this->invoke_protected( 'show_in_menu' ) );
	}

	public function test_meta_fields_contain_exactly_sixteen_documented_keys(): void {
		$fields = $this->invoke_protected( 'meta_fields' );

		self::assertSame( self::EXPECTED_META_KEYS, array_keys( $fields ) );
		self::assertCount( 16, $fields );
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

		self::assertSame( '', $fields['_vl_webinar_scheduled_start']['default'] );
		self::assertSame( '', $fields['_vl_webinar_scheduled_end']['default'] );
		self::assertSame( 'scheduled', $fields['_vl_webinar_status']['default'] );
		self::assertSame( 0, $fields['_vl_webinar_price']['default'] );
		self::assertSame( 0, $fields['_vl_webinar_cover_image_id']['default'] );
		self::assertSame( 'UAH', $fields['_vl_webinar_currency']['default'] );
		self::assertSame( 0, $fields['_vl_webinar_max_attendees']['default'] );
		self::assertSame( '', $fields['_vl_webinar_registration_opens_at']['default'] );
		self::assertSame( '', $fields['_vl_webinar_registration_closes_at']['default'] );
		self::assertSame( '', $fields['_vl_webinar_zoom_meeting_id']['default'] );
		self::assertSame( '', $fields['_vl_webinar_zoom_join_url']['default'] );
		self::assertSame( '', $fields['_vl_webinar_zoom_start_url']['default'] );
		self::assertSame( '', $fields['_vl_webinar_zoom_password']['default'] );
		self::assertSame( '', $fields['_vl_webinar_recording_url']['default'] );
		self::assertSame( 0, $fields['_vl_webinar_recording_access_days']['default'] );
		self::assertSame( '', $fields['_vl_webinar_preview_video_url']['default'] );
	}

	public function test_sanitize_iso8601(): void {
		self::assertSame( '2026-05-01T10:00:00Z', $this->invoke_sanitizer( 'sanitize_iso8601', '2026-05-01T10:00:00Z' ) );
		self::assertSame( '2026-05-01T10:00:00+02:00', $this->invoke_sanitizer( 'sanitize_iso8601', '2026-05-01T10:00:00+02:00' ) );
		self::assertSame( '', $this->invoke_sanitizer( 'sanitize_iso8601', 'not-a-date' ) );
		self::assertSame( '', $this->invoke_sanitizer( 'sanitize_iso8601', '' ) );
		self::assertSame( '', $this->invoke_sanitizer( 'sanitize_iso8601', null ) );
		self::assertSame( '', $this->invoke_sanitizer( 'sanitize_iso8601', 12345 ) );
	}

	public function test_sanitize_currency_code(): void {
		self::assertSame( 'UAH', $this->invoke_sanitizer( 'sanitize_currency_code', 'uah' ) );
		self::assertSame( 'USD', $this->invoke_sanitizer( 'sanitize_currency_code', 'USD' ) );
		self::assertSame( 'UAH', $this->invoke_sanitizer( 'sanitize_currency_code', 'TOOLONG' ) );
		self::assertSame( 'UAH', $this->invoke_sanitizer( 'sanitize_currency_code', '12' ) );
		self::assertSame( 'UAH', $this->invoke_sanitizer( 'sanitize_currency_code', '' ) );
	}

	public function test_sanitize_price_uah(): void {
		self::assertSame( 199.50, $this->invoke_sanitizer( 'sanitize_price_uah', '199.50' ) );
		self::assertSame( 199.0, $this->invoke_sanitizer( 'sanitize_price_uah', 199 ) );
		self::assertSame( 0.0, $this->invoke_sanitizer( 'sanitize_price_uah', -5 ) );
		self::assertSame( 0.0, $this->invoke_sanitizer( 'sanitize_price_uah', 'not-a-number' ) );
		self::assertSame( 200.0, $this->invoke_sanitizer( 'sanitize_price_uah', 199.999 ) );
	}

	public function test_price_meta_uses_uah_decimal_shape(): void {
		$fields = $this->invoke_protected( 'meta_fields' );

		self::assertSame( 'number', $fields['_vl_webinar_price']['type'] );
		self::assertIsCallable( $fields['_vl_webinar_price']['sanitize_callback'] );
	}

	public function test_cover_image_id_meta_uses_absint_sanitizer(): void {
		$fields = $this->invoke_protected( 'meta_fields' );

		self::assertSame( 'integer', $fields['_vl_webinar_cover_image_id']['type'] );
		self::assertSame( 'absint', $fields['_vl_webinar_cover_image_id']['sanitize_callback'] );
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

	private function invoke_protected( string $method ): mixed {
		$reflection = new ReflectionMethod( WebinarType::class, $method );
		return $reflection->invoke( new WebinarType() );
	}

	private function invoke_sanitizer( string $method, mixed $value ): mixed {
		$reflection = new ReflectionMethod( WebinarType::class, $method );
		return $reflection->invoke( null, $value );
	}
}
