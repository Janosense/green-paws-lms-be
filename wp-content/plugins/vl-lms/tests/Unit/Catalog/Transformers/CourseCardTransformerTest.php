<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Catalog\Transformers;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Catalog\TaxonomyTermTransformer;
use VL\LMS\Catalog\Transformers\CourseCardTransformer;
use VL\LMS\Catalog\Transformers\CoverImageTransformer;
use VL\LMS\Catalog\Transformers\LeadInstructorTransformer;
use VL\LMS\Domain\CourseInstructor\CourseInstructor;
use VL\LMS\Domain\CourseInstructor\InstructorEntityType;
use VL\LMS\Domain\CourseInstructor\InstructorRole;
use WP_Post;
use WP_Term;

final class CourseCardTransformerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private CourseCardTransformer $transformer;

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
		Functions\when( 'get_post_meta' )->alias(
			fn ( int $post_id, string $key ): mixed => $this->meta[ $key ][ $post_id ] ?? ''
		);
		Functions\when( 'get_post' )->justReturn( null );
		Functions\when( 'wp_get_attachment_image_src' )->justReturn( false );
		Functions\when( 'get_user_by' )->justReturn( false );

		$this->transformer = new CourseCardTransformer(
			new CoverImageTransformer(),
			new LeadInstructorTransformer(),
			new TaxonomyTermTransformer(),
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_full_card_with_cover_lead_and_terms(): void {
		$this->meta = [
			'_vl_course_type'            => [ 100 => 'self_paced' ],
			'_vl_course_duration_hours'  => [ 100 => 10.5 ],
			'_vl_course_price'           => [ 100 => 1500.0 ],
			'_vl_course_currency'        => [ 100 => 'UAH' ],
			'_vl_course_enrollment_open' => [ 100 => '1' ],
			'_vl_course_cover_image_id'  => [ 100 => 11 ],
		];

		Functions\when( 'get_post' )->justReturn( Mockery::mock( 'WP_Post' ) );
		Functions\when( 'wp_get_attachment_image_src' )->alias(
			static function ( int $id, string $size ): array|false {
				return match ( $size ) {
					'thumbnail'    => [ 'https://t/150.jpg', 150, 150, true ],
					'medium_large' => [ 'https://t/768.jpg', 768, 432, true ],
					'full'         => [ 'https://t/full.jpg', 1920, 1080, false ],
					default        => false,
				};
			}
		);

		$user               = Mockery::mock( 'WP_User' );
		$user->ID           = 5;
		$user->display_name = 'Dr. Olena Petrenko';
		Functions\when( 'get_user_by' )->justReturn( $user );
		Functions\when( 'get_user_meta' )->justReturn( '0' );
		Functions\when( 'get_avatar_url' )->justReturn( 'https://gravatar.test/abc?s=96' );

		$card = $this->transformer->transform(
			$this->post( 100, 'cardiology-fundamentals', 'Cardiology Fundamentals', 'A short blurb.' ),
			$this->lead( 5 ),
			[
				'vl_difficulty' => [ $this->term( 'basic', 'Basic', 'vl_difficulty' ) ],
				'vl_category'   => [ $this->term( 'cardiology', 'Cardiology', 'vl_category' ) ],
				'vl_specialty'  => [ $this->term( 'therapist', 'Therapist', 'vl_specialty' ) ],
				'vl_tag'        => [ $this->term( 'echo', 'Echo', 'vl_tag' ) ],
			]
		);

		self::assertSame( 100, $card['id'] );
		self::assertSame( 'cardiology-fundamentals', $card['slug'] );
		self::assertSame( 'Cardiology Fundamentals', $card['title'] );
		self::assertSame( 'A short blurb.', $card['excerpt'] );
		self::assertSame( 'self_paced', $card['type'] );
		self::assertSame( 10.5, $card['duration_hours'] );
		self::assertSame( 1500.0, $card['price'] );
		self::assertSame( 'UAH', $card['currency'] );
		self::assertTrue( $card['enrollment_open'] );
		self::assertSame(
			[
				'slug' => 'basic',
				'name' => 'Basic',
			],
			$card['difficulty']
		);
		self::assertCount( 1, $card['categories'] );
		self::assertSame( 'cardiology', $card['categories'][0]['slug'] );
		self::assertNull( $card['categories'][0]['parent_slug'] );
		self::assertCount( 1, $card['specialties'] );
		self::assertCount( 1, $card['tags'] );
		self::assertIsArray( $card['cover'] );
		self::assertSame( 'https://t/768.jpg', $card['cover']['card']['url'] );
		self::assertIsArray( $card['lead_instructor'] );
		self::assertSame( 5, $card['lead_instructor']['id'] );
		self::assertSame( '/courses/cardiology-fundamentals', $card['permalink'] );
	}

	public function test_card_with_no_cover_emits_null(): void {
		$this->meta = [
			'_vl_course_cover_image_id' => [ 100 => 0 ],
		];

		$card = $this->transformer->transform(
			$this->post( 100, 'free-course', 'Free Course', '' ),
			null,
			[]
		);

		self::assertNull( $card['cover'] );
	}

	public function test_card_with_no_lead_emits_null(): void {
		$card = $this->transformer->transform(
			$this->post( 100, 'free-course', 'Free Course', '' ),
			null,
			[]
		);

		self::assertNull( $card['lead_instructor'] );
	}

	public function test_card_with_zero_price_is_emitted_as_float_zero(): void {
		$this->meta = [
			'_vl_course_price' => [ 100 => 0 ],
		];

		$card = $this->transformer->transform(
			$this->post( 100, 'free-course', 'Free Course', '' ),
			null,
			[]
		);

		self::assertSame( 0.0, $card['price'] );
	}

	private function post( int $id, string $slug, string $title, string $excerpt ): WP_Post {
		$post               = Mockery::mock( 'WP_Post' );
		$post->ID           = $id;
		$post->post_name    = $slug;
		$post->post_title   = $title;
		$post->post_excerpt = $excerpt;
		$post->post_type    = 'vl_course';
		$post->post_status  = 'publish';
		return $post;
	}

	private function term( string $slug, string $name, string $taxonomy ): WP_Term {
		$term           = Mockery::mock( 'WP_Term' );
		$term->term_id  = crc32( $taxonomy . ':' . $slug );
		$term->slug     = $slug;
		$term->name     = $name;
		$term->taxonomy = $taxonomy;
		$term->parent   = 0;
		$term->count    = 1;
		return $term;
	}

	private function lead( int $user_id ): CourseInstructor {
		return new CourseInstructor(
			id: 1,
			entity_type: InstructorEntityType::COURSE,
			entity_id: 100,
			user_id: $user_id,
			role_in_course: InstructorRole::LEAD,
			display_order: 0,
			assigned_at: '2026-01-01 00:00:00',
			assigned_by: $user_id,
		);
	}
}
