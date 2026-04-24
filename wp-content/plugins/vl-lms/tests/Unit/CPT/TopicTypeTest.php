<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\CPT;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use VL\LMS\CPT\TopicType;

final class TopicTypeTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Keys (and only these keys) that the TopicType registers as meta.
	 *
	 * @var list<string>
	 */
	private const array EXPECTED_META_KEYS = [
		'_vl_topic_video_url',
		'_vl_topic_video_provider',
		'_vl_topic_duration_seconds',
	];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg( 1 );
		// String callables in meta_fields() reference WP functions that are
		// not loaded in unit tests. Declare stubs so is_callable() returns true.
		Functions\when( 'absint' )->returnArg( 1 );
		Functions\when( 'esc_url_raw' )->returnArg( 1 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_post_type_returns_vl_topic(): void {
		self::assertSame( 'vl_topic', $this->invoke_protected( 'post_type' ) );
	}

	public function test_capability_type_pair(): void {
		self::assertSame( [ 'vl_topic', 'vl_topics' ], $this->invoke_protected( 'capability_type' ) );
	}

	public function test_supports_contains_exactly_required_features(): void {
		self::assertSame(
			[ 'title', 'editor', 'custom-fields', 'page-attributes' ],
			$this->invoke_protected( 'supports' )
		);
	}

	public function test_supports_omits_thumbnail_and_author(): void {
		$supports = $this->invoke_protected( 'supports' );

		self::assertNotContains( 'thumbnail', $supports );
		self::assertNotContains( 'author', $supports );
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

	public function test_meta_fields_contain_exactly_three_documented_keys(): void {
		$fields = $this->invoke_protected( 'meta_fields' );

		self::assertSame( self::EXPECTED_META_KEYS, array_keys( $fields ) );
		self::assertCount( 3, $fields );
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

		self::assertSame( '', $fields['_vl_topic_video_url']['default'] );
		self::assertSame( 'file', $fields['_vl_topic_video_provider']['default'] );
		self::assertSame( 0, $fields['_vl_topic_duration_seconds']['default'] );
	}

	public function test_sanitize_video_provider(): void {
		self::assertSame( 'vimeo', $this->invoke_sanitizer( 'sanitize_video_provider', 'vimeo' ) );
		self::assertSame( 'youtube', $this->invoke_sanitizer( 'sanitize_video_provider', 'youtube' ) );
		self::assertSame( 'file', $this->invoke_sanitizer( 'sanitize_video_provider', 'file' ) );
		self::assertSame( 'embed', $this->invoke_sanitizer( 'sanitize_video_provider', 'embed' ) );
		self::assertSame( 'file', $this->invoke_sanitizer( 'sanitize_video_provider', 'twitch' ) );
		self::assertSame( 'file', $this->invoke_sanitizer( 'sanitize_video_provider', '' ) );
		self::assertSame( 'file', $this->invoke_sanitizer( 'sanitize_video_provider', 123 ) );
	}

	private function invoke_protected( string $method ): mixed {
		$reflection = new ReflectionMethod( TopicType::class, $method );
		return $reflection->invoke( new TopicType() );
	}

	private function invoke_sanitizer( string $method, mixed $value ): mixed {
		$reflection = new ReflectionMethod( TopicType::class, $method );
		return $reflection->invoke( null, $value );
	}
}
