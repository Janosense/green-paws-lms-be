<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\CPT;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use VL\LMS\CPT\LessonType;

final class LessonTypeTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Keys (and only these keys) that the LessonType registers as meta.
	 *
	 * @var list<string>
	 */
	private const array EXPECTED_META_KEYS = [
		'_vl_lesson_video_url',
		'_vl_lesson_video_provider',
		'_vl_lesson_duration_seconds',
		'_vl_lesson_is_preview',
		'_vl_lesson_attachments',
		'_vl_lesson_requires_completion',
	];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg( 1 );
		// String callables in meta_fields() — and the helpers used inside
		// sanitize_attachments — reference WP functions that are not loaded
		// in unit tests. Declare stubs so is_callable() returns true and
		// sanitize_attachments can run without touching WP core.
		Functions\when( 'absint' )->alias( static fn ( mixed $v ): int => (int) $v );
		Functions\when( 'rest_sanitize_boolean' )->returnArg( 1 );
		Functions\when( 'esc_url_raw' )->returnArg( 1 );
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_post_type_returns_vl_lesson(): void {
		self::assertSame( 'vl_lesson', $this->invoke_protected( 'post_type' ) );
	}

	public function test_capability_type_pair(): void {
		self::assertSame( [ 'vl_lesson', 'vl_lessons' ], $this->invoke_protected( 'capability_type' ) );
	}

	public function test_supports_contains_exactly_required_features(): void {
		self::assertSame(
			[ 'title', 'editor', 'thumbnail', 'custom-fields', 'page-attributes' ],
			$this->invoke_protected( 'supports' )
		);
	}

	public function test_menu_icon_is_dashicons_video_alt3(): void {
		self::assertSame( 'dashicons-video-alt3', $this->invoke_protected( 'menu_icon' ) );
	}

	public function test_hierarchical_returns_false(): void {
		self::assertFalse( $this->invoke_protected( 'hierarchical' ) );
	}

	public function test_meta_fields_contain_exactly_six_documented_keys(): void {
		$fields = $this->invoke_protected( 'meta_fields' );

		self::assertSame( self::EXPECTED_META_KEYS, array_keys( $fields ) );
		self::assertCount( 6, $fields );
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

		self::assertSame( '', $fields['_vl_lesson_video_url']['default'] );
		self::assertSame( 'file', $fields['_vl_lesson_video_provider']['default'] );
		self::assertSame( 0, $fields['_vl_lesson_duration_seconds']['default'] );
		self::assertFalse( $fields['_vl_lesson_is_preview']['default'] );
		self::assertSame( [], $fields['_vl_lesson_attachments']['default'] );
		self::assertTrue( $fields['_vl_lesson_requires_completion']['default'] );
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

	public function test_sanitize_attachments_rejects_non_array_input(): void {
		self::assertSame( [], $this->invoke_sanitizer( 'sanitize_attachments', 'string' ) );
		self::assertSame( [], $this->invoke_sanitizer( 'sanitize_attachments', null ) );
		self::assertSame( [], $this->invoke_sanitizer( 'sanitize_attachments', 42 ) );
	}

	public function test_sanitize_attachments_returns_empty_for_empty_array(): void {
		self::assertSame( [], $this->invoke_sanitizer( 'sanitize_attachments', [] ) );
	}

	public function test_sanitize_attachments_preserves_valid_element(): void {
		$result = $this->invoke_sanitizer(
			'sanitize_attachments',
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

	public function test_sanitize_attachments_defaults_missing_name(): void {
		$result = $this->invoke_sanitizer(
			'sanitize_attachments',
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

	public function test_sanitize_attachments_defaults_missing_size(): void {
		$result = $this->invoke_sanitizer(
			'sanitize_attachments',
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

	public function test_sanitize_attachments_drops_elements_with_empty_url(): void {
		$result = $this->invoke_sanitizer(
			'sanitize_attachments',
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

	public function test_sanitize_attachments_strips_unexpected_keys(): void {
		$result = $this->invoke_sanitizer(
			'sanitize_attachments',
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

	public function test_sanitize_attachments_filters_and_reindexes_mixed_input(): void {
		$result = $this->invoke_sanitizer(
			'sanitize_attachments',
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
		$reflection = new ReflectionMethod( LessonType::class, $method );
		return $reflection->invoke( new LessonType() );
	}

	private function invoke_sanitizer( string $method, mixed $value ): mixed {
		$reflection = new ReflectionMethod( LessonType::class, $method );
		return $reflection->invoke( null, $value );
	}
}
