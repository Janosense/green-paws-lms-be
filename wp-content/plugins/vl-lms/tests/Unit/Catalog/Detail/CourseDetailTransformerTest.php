<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Catalog\Detail;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Catalog\Detail\CourseDetailTransformer;
use VL\LMS\Catalog\Detail\CurriculumTransformer;
use VL\LMS\Catalog\Detail\InstructorListTransformer;
use VL\LMS\Catalog\Detail\LessonSummaryTransformer;
use VL\LMS\Catalog\Detail\ModuleTransformer;
use VL\LMS\Catalog\Detail\SeoBlockTransformer;
use VL\LMS\Catalog\TaxonomyTermTransformer;
use VL\LMS\Catalog\Transformers\CoverImageTransformer;
use VL\LMS\Domain\CourseInstructor\CourseInstructor;
use VL\LMS\Domain\CourseInstructor\InstructorEntityType;
use VL\LMS\Domain\CourseInstructor\InstructorRole;
use VL\LMS\Repositories\CourseInstructorRepository;
use VL\LMS\Tests\Unit\Catalog\Detail\Support\FakePostFinder;
use WP_Post;
use WP_Term;
use WP_User;

final class CourseDetailTransformerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private CourseDetailTransformer $transformer;

	/** @var Mockery\MockInterface&CourseInstructorRepository */
	private $instructors;

	/** @var array<string, array<int, mixed>> */
	private array $meta = [];

	/** @var array<int, list<WP_Term>> */
	private array $terms_by_post = [];

	/** @var array<int, WP_User> */
	private array $users = [];

	/** @var array<string, array<int, mixed>> */
	private array $user_meta = [];

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
		Functions\when( 'wp_get_object_terms' )->alias(
			fn ( int $post_id, array $taxonomies ): array => $this->terms_by_post[ $post_id ] ?? []
		);
		Functions\when( 'get_terms' )->justReturn( [] );
		Functions\when( 'get_post' )->justReturn( null );
		Functions\when( 'wp_get_attachment_image_src' )->justReturn( false );
		Functions\when( 'get_user_by' )->alias(
			fn ( string $field, int $id ): WP_User|false => $this->users[ $id ] ?? false
		);
		Functions\when( 'get_user_meta' )->alias(
			fn ( int $user_id, string $key, bool $single ): mixed => $this->user_meta[ $key ][ $user_id ] ?? ''
		);
		Functions\when( 'get_avatar_url' )->alias(
			static fn ( int $user_id, array $args ): string => "https://gravatar.test/{$user_id}?s=" . (int) ( $args['size'] ?? 96 )
		);

		$this->instructors = Mockery::mock( CourseInstructorRepository::class );
		$this->instructors->shouldReceive( 'list_for_entity' )->andReturn( [] )->byDefault();

		$lesson_summary = new LessonSummaryTransformer();
		$module         = new ModuleTransformer( $lesson_summary );
		$curriculum     = new CurriculumTransformer( $module, $lesson_summary, new FakePostFinder() );

		$this->transformer = new CourseDetailTransformer(
			new CoverImageTransformer(),
			new TaxonomyTermTransformer(),
			new InstructorListTransformer(),
			$curriculum,
			new SeoBlockTransformer(),
			$this->instructors,
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_full_payload_with_all_fields_populated(): void {
		$this->meta = [
			'_vl_course_type'                 => [ 100 => 'cohort' ],
			'_vl_course_duration_hours'       => [ 100 => 10.5 ],
			'_vl_course_price'                => [ 100 => 1500.0 ],
			'_vl_course_currency'             => [ 100 => 'UAH' ],
			'_vl_course_enrollment_open'      => [ 100 => '1' ],
			'_vl_course_enrollment_opens_at'  => [ 100 => '2026-05-01T08:00:00+00:00' ],
			'_vl_course_enrollment_closes_at' => [ 100 => '2026-05-15T20:00:00+00:00' ],
			'_vl_course_starts_at'            => [ 100 => '2026-06-01T09:00:00+00:00' ],
			'_vl_course_ends_at'              => [ 100 => '2026-08-01T18:00:00+00:00' ],
			'_vl_course_max_students'         => [ 100 => 100 ],
			'_vl_course_preview_video_url'    => [ 100 => 'https://preview.test/v' ],
			'_vl_course_certificate_enabled'  => [ 100 => '1' ],
			'_vl_course_passing_threshold'    => [ 100 => 80 ],
			'_vl_course_cover_image_id'       => [ 100 => 0 ],
		];

		$payload = $this->transformer->transform(
			$this->post( 100, 'cardiology-fundamentals', 'Cardiology Fundamentals', 'Cardiology overview.', '<p>Body</p>' )
		);

		self::assertSame( 100, $payload['id'] );
		self::assertSame( 'cardiology-fundamentals', $payload['slug'] );
		self::assertSame( 'Cardiology Fundamentals', $payload['title'] );
		self::assertSame( 'Cardiology overview.', $payload['excerpt'] );
		self::assertSame( '<p>Body</p>', $payload['content'] );
		self::assertSame( 'cohort', $payload['type'] );
		self::assertSame( 10.5, $payload['duration_hours'] );
		self::assertSame( 1500.0, $payload['price'] );
		self::assertSame( 'UAH', $payload['currency'] );
		self::assertTrue( $payload['enrollment_open'] );
		self::assertSame( '2026-05-01T08:00:00+00:00', $payload['enrollment_opens_at'] );
		self::assertSame( '2026-05-15T20:00:00+00:00', $payload['enrollment_closes_at'] );
		self::assertSame( '2026-06-01T09:00:00+00:00', $payload['starts_at'] );
		self::assertSame( '2026-08-01T18:00:00+00:00', $payload['ends_at'] );
		self::assertSame( 100, $payload['max_students'] );
		self::assertSame( 'https://preview.test/v', $payload['preview_video_url'] );
		self::assertTrue( $payload['certificate_enabled'] );
		self::assertSame( 80, $payload['passing_threshold'] );
		self::assertSame( 'Cardiology Fundamentals | Green Paws LMS', $payload['seo']['title'] );
		self::assertSame( '/courses/cardiology-fundamentals', $payload['seo']['canonical_path'] );
	}

	public function test_self_paced_course_returns_null_schedule_fields(): void {
		$this->meta = [
			'_vl_course_type' => [ 100 => 'self_paced' ],
		];

		$payload = $this->transformer->transform( $this->post( 100, 's', 'S', '', '' ) );

		self::assertSame( 'self_paced', $payload['type'] );
		self::assertNull( $payload['enrollment_opens_at'] );
		self::assertNull( $payload['enrollment_closes_at'] );
		self::assertNull( $payload['starts_at'] );
		self::assertNull( $payload['ends_at'] );
	}

	public function test_certificate_disabled_returns_null_passing_threshold(): void {
		$this->meta = [
			'_vl_course_certificate_enabled' => [ 100 => '0' ],
			'_vl_course_passing_threshold'   => [ 100 => 80 ],
		];

		$payload = $this->transformer->transform( $this->post( 100, 's', 'S', '', '' ) );

		self::assertFalse( $payload['certificate_enabled'] );
		self::assertNull( $payload['passing_threshold'] );
	}

	public function test_certificate_enabled_returns_int_passing_threshold(): void {
		$this->meta = [
			'_vl_course_certificate_enabled' => [ 100 => '1' ],
			'_vl_course_passing_threshold'   => [ 100 => 70 ],
		];

		$payload = $this->transformer->transform( $this->post( 100, 's', 'S', '', '' ) );

		self::assertTrue( $payload['certificate_enabled'] );
		self::assertSame( 70, $payload['passing_threshold'] );
	}

	public function test_empty_curriculum_emits_both_arrays_empty(): void {
		$payload = $this->transformer->transform( $this->post( 100, 's', 'S', '', '' ) );

		self::assertSame( [], $payload['curriculum']['modules'] );
		self::assertSame( [], $payload['curriculum']['orphan_lessons'] );
	}

	public function test_instructors_list_includes_role_and_bio(): void {
		$this->users[5]                          = $this->user( 5, 'Lead' );
		$this->users[8]                          = $this->user( 8, 'Co' );
		$this->user_meta['vl_instructor_bio'][5] = '<p>Bio for lead.</p>';

		$this->instructors
			->shouldReceive( 'list_for_entity' )
			->with( InstructorEntityType::COURSE, 100 )
			->andReturn(
				[
					$this->assignment( 1, 5, 100, InstructorRole::LEAD, 0 ),
					$this->assignment( 2, 8, 100, InstructorRole::CO_INSTRUCTOR, 1 ),
				]
			);

		$payload = $this->transformer->transform( $this->post( 100, 's', 'S', '', '' ) );

		self::assertCount( 2, $payload['instructors'] );
		self::assertSame( 'lead', $payload['instructors'][0]['role_in_course'] );
		self::assertSame( '<p>Bio for lead.</p>', $payload['instructors'][0]['bio'] );
		self::assertSame( 'co_instructor', $payload['instructors'][1]['role_in_course'] );
		self::assertSame( '', $payload['instructors'][1]['bio'] );
	}

	public function test_seo_block_present_with_canonical_path(): void {
		$payload = $this->transformer->transform( $this->post( 100, 'my-slug', 'My Title', 'desc', '' ) );

		self::assertSame( 'My Title | Green Paws LMS', $payload['seo']['title'] );
		self::assertSame( '/courses/my-slug', $payload['seo']['canonical_path'] );
		self::assertSame( 'desc', $payload['seo']['description'] );
		self::assertNull( $payload['seo']['og_image'] );
	}

	private function post( int $id, string $slug, string $title, string $excerpt, string $content ): WP_Post {
		$post               = Mockery::mock( 'WP_Post' );
		$post->ID           = $id;
		$post->post_name    = $slug;
		$post->post_title   = $title;
		$post->post_excerpt = $excerpt;
		$post->post_content = $content;
		$post->post_type    = 'vl_course';
		$post->post_status  = 'publish';
		$post->post_parent  = 0;
		return $post;
	}

	private function user( int $id, string $display_name ): WP_User {
		$user               = Mockery::mock( 'WP_User' );
		$user->ID           = $id;
		$user->display_name = $display_name;
		return $user;
	}

	private function assignment(
		int $id,
		int $user_id,
		int $entity_id,
		InstructorRole $role,
		int $display_order
	): CourseInstructor {
		return new CourseInstructor(
			id: $id,
			entity_type: InstructorEntityType::COURSE,
			entity_id: $entity_id,
			user_id: $user_id,
			role_in_course: $role,
			display_order: $display_order,
			assigned_at: '2026-01-01 00:00:00',
			assigned_by: $user_id,
		);
	}
}
