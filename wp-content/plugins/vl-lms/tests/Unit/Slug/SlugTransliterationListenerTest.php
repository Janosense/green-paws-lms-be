<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Slug;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Slug\CyrillicTransliterator;
use VL\LMS\Slug\SlugTransliterationListener;

final class SlugTransliterationListenerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private SlugTransliterationListener $listener;

	/**
	 * Mocked stored `post_name` values keyed by post ID. A missing key means
	 * no persisted slug (empty string), matching `get_post_field` for an
	 * `auto-draft` or a draft that never received a custom slug.
	 *
	 * @var array<int, string>
	 */
	private array $existing_slug = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'wp_unique_post_slug' )->alias(
			static fn ( string $slug ): string => $slug
		);
		Functions\when( 'get_post_field' )->alias(
			fn ( string $field, int $post_id ) => $this->existing_slug[ $post_id ] ?? ''
		);

		$this->listener = new SlugTransliterationListener( new CyrillicTransliterator() );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_inner_course_cpt_is_transliterated_on_every_save(): void {
		// An already-persisted slug must NOT block transliteration for
		// inner-course CPTs — that tier always rewrites, no SEO concern.
		$this->existing_slug[42] = 'test-urok';

		$data = $this->filter_with(
			[
				'post_type'   => 'vl_lesson',
				'post_name'   => 'тест-урок',
				'post_status' => 'publish',
			],
			[ 'ID' => 42 ]
		);

		self::assertSame( 'test-urok', $data['post_name'] );
	}

	public function test_catalog_cpt_is_transliterated_on_initial_insert(): void {
		// ID === 0 means wp_insert_post hasn't created a row yet.
		$data = $this->filter_with(
			[
				'post_type'   => 'vl_course',
				'post_name'   => 'тест-когорта',
				'post_status' => 'publish',
			],
			[ 'ID' => 0 ]
		);

		self::assertSame( 'test-kohorta', $data['post_name'] );
	}

	public function test_catalog_cpt_is_transliterated_when_no_slug_persisted_yet(): void {
		// Gutenberg flow: the row exists (auto-draft, or a draft saved by
		// autosave) but has no persisted slug. The publish transition first
		// derives a slug from the title, so we treat this as creation.
		// `existing_slug` has no entry for 101 → stored post_name is empty.

		$data = $this->filter_with(
			[
				'post_type'   => 'vl_course',
				'post_name'   => 'тест-когорта',
				'post_status' => 'publish',
			],
			[ 'ID' => 101 ]
		);

		self::assertSame( 'test-kohorta', $data['post_name'] );
	}

	public function test_catalog_cpt_is_left_alone_once_a_slug_is_persisted(): void {
		// Once a non-empty slug has been persisted, it is editor-territory.
		// We never silently rewrite — even if it's still Cyrillic — to
		// protect URLs that may already be indexed.
		$this->existing_slug[202] = 'тест-когорта';

		$data = $this->filter_with(
			[
				'post_type'   => 'vl_course',
				'post_name'   => 'тест-когорта',
				'post_status' => 'publish',
			],
			[ 'ID' => 202 ]
		);

		self::assertSame( 'тест-когорта', $data['post_name'] );
	}

	public function test_webinar_cpt_follows_create_only_policy(): void {
		// vl_webinar shares the catalog policy with vl_course.
		$data = $this->filter_with(
			[
				'post_type'   => 'vl_webinar',
				'post_name'   => 'весняна-кардіологія',
				'post_status' => 'publish',
			],
			[ 'ID' => 0 ]
		);
		self::assertSame( 'vesniana-kardiolohiia', $data['post_name'] );

		$this->existing_slug[303] = 'vesniana-kardiolohiia';
		$data                     = $this->filter_with(
			[
				'post_type'   => 'vl_webinar',
				'post_name'   => 'весняна-кардіологія',
				'post_status' => 'publish',
			],
			[ 'ID' => 303 ]
		);
		self::assertSame( 'весняна-кардіологія', $data['post_name'] );
	}

	public function test_unrelated_post_types_are_untouched(): void {
		$data = $this->filter_with(
			[
				'post_type'   => 'post',
				'post_name'   => 'тест-стаття',
				'post_status' => 'publish',
			],
			[ 'ID' => 0 ]
		);

		self::assertSame( 'тест-стаття', $data['post_name'] );
	}

	public function test_empty_slug_is_returned_unchanged(): void {
		$data = $this->filter_with(
			[
				'post_type'   => 'vl_course',
				'post_name'   => '',
				'post_status' => 'publish',
			],
			[ 'ID' => 0 ]
		);

		self::assertSame( '', $data['post_name'] );
	}

	public function test_already_ascii_slug_is_left_unchanged(): void {
		$data = $this->filter_with(
			[
				'post_type'   => 'vl_course',
				'post_name'   => 'cardiology-fundamentals',
				'post_status' => 'publish',
			],
			[ 'ID' => 0 ]
		);

		self::assertSame( 'cardiology-fundamentals', $data['post_name'] );
	}

	/**
	 * @param array<string, mixed> $data
	 * @param array<string, mixed> $postarr
	 * @return array<string, mixed>
	 */
	private function filter_with( array $data, array $postarr ): array {
		return $this->listener->filter_insert_data( $data, $postarr );
	}
}
