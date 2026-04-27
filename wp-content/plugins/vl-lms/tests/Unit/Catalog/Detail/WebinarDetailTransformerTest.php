<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Catalog\Detail;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Catalog\Detail\InstructorListTransformer;
use VL\LMS\Catalog\Detail\MaterialsTransformer;
use VL\LMS\Catalog\Detail\RegistrationWindow;
use VL\LMS\Catalog\Detail\SeoBlockTransformer;
use VL\LMS\Catalog\Detail\WebinarDetailTransformer;
use VL\LMS\Catalog\TaxonomyTermTransformer;
use VL\LMS\Catalog\Transformers\CoverImageTransformer;
use VL\LMS\Domain\CourseInstructor\InstructorEntityType;
use VL\LMS\Repositories\CourseInstructorRepository;
use WP_Post;

final class WebinarDetailTransformerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private WebinarDetailTransformer $transformer;

	/** @var Mockery\MockInterface&CourseInstructorRepository */
	private $instructors;

	/** @var array<string, array<int, mixed>> */
	private array $meta = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'get_the_title' )->alias(
			static fn ( WP_Post $p ): string => (string) $p->post_title
		);
		Functions\when( 'get_the_excerpt' )->alias(
			static fn ( WP_Post $p ): string => (string) $p->post_excerpt
		);
		Functions\when( 'wp_strip_all_tags' )->returnArg();
		Functions\when( 'apply_filters' )->alias(
			static fn ( string $tag, mixed $value ): mixed => $value
		);
		Functions\when( 'get_post_meta' )->alias(
			fn ( int $post_id, string $key ): mixed => $this->meta[ $key ][ $post_id ] ?? ''
		);
		Functions\when( 'wp_get_object_terms' )->justReturn( [] );
		Functions\when( 'get_terms' )->justReturn( [] );
		Functions\when( 'get_post' )->justReturn( null );
		Functions\when( 'wp_get_attachment_image_src' )->justReturn( false );
		Functions\when( 'wp_json_encode' )->alias(
			static fn ( mixed $data ): string|false => json_encode( $data )
		);

		$this->instructors = Mockery::mock( CourseInstructorRepository::class );
		$this->instructors->shouldReceive( 'list_for_entity' )->andReturn( [] )->byDefault();

		$this->transformer = new WebinarDetailTransformer(
			new CoverImageTransformer(),
			new TaxonomyTermTransformer(),
			new InstructorListTransformer(),
			new MaterialsTransformer(),
			new RegistrationWindow(),
			new SeoBlockTransformer(),
			$this->instructors,
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_happy_path_includes_documented_fields(): void {
		$this->meta = [
			'_vl_webinar_scheduled_start'        => [ 200 => '2026-05-20T18:00:00+00:00' ],
			'_vl_webinar_scheduled_end'          => [ 200 => '2026-05-20T20:00:00+00:00' ],
			'_vl_webinar_status'                 => [ 200 => 'scheduled' ],
			'_vl_webinar_price'                  => [ 200 => 500.0 ],
			'_vl_webinar_currency'               => [ 200 => 'UAH' ],
			'_vl_webinar_max_attendees'          => [ 200 => 200 ],
			'_vl_webinar_registration_opens_at'  => [ 200 => '2026-04-01T00:00:00+00:00' ],
			'_vl_webinar_registration_closes_at' => [ 200 => '2026-05-20T17:00:00+00:00' ],
			'_vl_webinar_preview_video_url'      => [ 200 => 'https://preview.test/v' ],
			'_vl_webinar_recording_access_days'  => [ 200 => 30 ],
			'_vl_webinar_materials'              => [
				200 => [
					[
						'url'  => 'https://m.test/slides.pdf',
						'name' => 'Slides',
						'size' => 1234567,
					],
				],
			],
		];

		$payload = $this->transformer->transform(
			$this->post( 200, 'spring-cardiology', 'Spring Cardiology', 'live Q&A', '<p>desc</p>' )
		);

		self::assertSame( 200, $payload['id'] );
		self::assertSame( 'spring-cardiology', $payload['slug'] );
		self::assertSame( '<p>desc</p>', $payload['content'] );
		self::assertSame( '2026-05-20T18:00:00+00:00', $payload['scheduled_start'] );
		self::assertSame( 'scheduled', $payload['status'] );
		self::assertSame( 500.0, $payload['price'] );
		self::assertSame( 200, $payload['max_attendees'] );
		self::assertTrue( $payload['recording_offered'] );
		self::assertSame( 30, $payload['recording_access_days'] );
		self::assertCount( 1, $payload['materials'] );
		self::assertSame( '/webinars/spring-cardiology', $payload['seo']['canonical_path'] );
	}

	public function test_recording_offered_false_when_zero_days(): void {
		$this->meta = [
			'_vl_webinar_recording_access_days' => [ 200 => 0 ],
		];

		$payload = $this->transformer->transform( $this->post( 200, 'w', 'W', '', '' ) );

		self::assertFalse( $payload['recording_offered'] );
		self::assertSame( 0, $payload['recording_access_days'] );
	}

	public function test_does_not_leak_recording_url_or_zoom_credentials(): void {
		$this->meta = [
			'_vl_webinar_zoom_meeting_id' => [ 200 => 'leak-meeting' ],
			'_vl_webinar_zoom_join_url'   => [ 200 => 'https://zoom.test/join/leak' ],
			'_vl_webinar_zoom_start_url'  => [ 200 => 'https://zoom.test/start/leak' ],
			'_vl_webinar_zoom_password'   => [ 200 => 'leak-password' ],
			'_vl_webinar_recording_url'   => [ 200 => 'https://leak.test/rec.mp4' ],
		];

		$payload = $this->transformer->transform( $this->post( 200, 'w', 'W', '', '' ) );

		// Public response is the trust boundary — none of these private
		// keys may surface, in any spelling.
		$forbidden = [
			'_vl_webinar_zoom_meeting_id',
			'_vl_webinar_zoom_join_url',
			'_vl_webinar_zoom_start_url',
			'_vl_webinar_zoom_password',
			'_vl_webinar_recording_url',
			'zoom_meeting_id',
			'zoom_join_url',
			'zoom_start_url',
			'zoom_password',
			'recording_url',
		];
		foreach ( $forbidden as $key ) {
			self::assertArrayNotHasKey( $key, $payload, "{$key} must never leak in webinar detail" );
		}

		// The serialized JSON must not contain the leaked values either.
		$serialized = (string) wp_json_encode( $payload );
		self::assertStringNotContainsString( 'leak-meeting', $serialized );
		self::assertStringNotContainsString( 'zoom.test/join', $serialized );
		self::assertStringNotContainsString( 'zoom.test/start', $serialized );
		self::assertStringNotContainsString( 'leak-password', $serialized );
		self::assertStringNotContainsString( 'leak.test/rec.mp4', $serialized );
	}

	public function test_status_falls_back_to_scheduled_for_unknown_value(): void {
		$this->meta = [
			'_vl_webinar_status' => [ 200 => 'made-up' ],
		];

		$payload = $this->transformer->transform( $this->post( 200, 'w', 'W', '', '' ) );

		self::assertSame( 'scheduled', $payload['status'] );
	}

	public function test_invokes_repository_with_webinar_entity_type(): void {
		$this->instructors
			->shouldReceive( 'list_for_entity' )
			->once()
			->with( InstructorEntityType::WEBINAR, 200 )
			->andReturn( [] );

		$this->transformer->transform( $this->post( 200, 'w', 'W', '', '' ) );
	}

	private function post( int $id, string $slug, string $title, string $excerpt, string $content ): WP_Post {
		$post               = Mockery::mock( 'WP_Post' );
		$post->ID           = $id;
		$post->post_name    = $slug;
		$post->post_title   = $title;
		$post->post_excerpt = $excerpt;
		$post->post_content = $content;
		$post->post_type    = 'vl_webinar';
		$post->post_status  = 'publish';
		return $post;
	}
}
