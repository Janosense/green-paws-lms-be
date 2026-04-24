<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Services\CourseInstructors;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\CourseInstructor\InstructorEntityType;
use VL\LMS\Domain\CourseInstructor\InstructorRole;
use VL\LMS\Services\CourseInstructors\AuthorSyncService;
use VL\LMS\Services\CourseInstructors\CourseInstructorService;
use VL\LMS\Tests\Fixtures\InMemoryCourseInstructorRepository;

final class AuthorSyncServiceTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private InMemoryCourseInstructorRepository $repo;

	private CourseInstructorService $instructor_service;

	private AuthorSyncService $service;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'wp_is_post_autosave' )->justReturn( false );
		Functions\when( 'wp_is_post_revision' )->justReturn( false );

		$this->repo               = new InMemoryCourseInstructorRepository();
		$this->instructor_service = new CourseInstructorService( $this->repo );
		$this->service            = new AuthorSyncService( $this->repo );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Build a WP_Post mock. The AuthorSyncService only reads
	 * `post_author`, `post_type`, `post_status`, so those three
	 * properties are the only ones we populate.
	 */
	private function make_post(
		int $id,
		int $author_id,
		string $post_type = 'vl_course',
		string $post_status = 'publish'
	): \WP_Post {
		$post              = Mockery::mock( 'WP_Post' );
		$post->ID          = $id;
		$post->post_author = (string) $author_id;
		$post->post_type   = $post_type;
		$post->post_status = $post_status;
		return $post;
	}

	public function test_on_save_post_inserts_lead_row_for_fresh_course(): void {
		$post = $this->make_post( 500, 7 );

		$this->service->on_save_post( 500, $post, false );

		$assignment = $this->repo->find_assignment( InstructorEntityType::COURSE, 500, 7 );
		self::assertNotNull( $assignment );
		self::assertSame( InstructorRole::LEAD, $assignment->role_in_course );
		self::assertSame( 7, $assignment->assigned_by );
	}

	public function test_on_save_post_is_stable_on_second_call_with_same_author(): void {
		$post = $this->make_post( 500, 7 );

		$this->service->on_save_post( 500, $post, false );
		$this->service->on_save_post( 500, $post, true );

		$rows = $this->repo->list_for_entity( InstructorEntityType::COURSE, 500 );
		self::assertCount( 1, $rows );
		self::assertSame( InstructorRole::LEAD, $rows[0]->role_in_course );
	}

	public function test_on_save_post_demotes_previous_lead_when_author_changes(): void {
		$this->service->on_save_post( 500, $this->make_post( 500, 7 ), false );

		$this->service->on_save_post( 500, $this->make_post( 500, 8 ), true );

		$user_7 = $this->repo->find_assignment( InstructorEntityType::COURSE, 500, 7 );
		$user_8 = $this->repo->find_assignment( InstructorEntityType::COURSE, 500, 8 );

		self::assertNotNull( $user_7 );
		self::assertNotNull( $user_8 );
		self::assertSame( InstructorRole::CO_INSTRUCTOR, $user_7->role_in_course, 'Previous lead must be demoted, not deleted.' );
		self::assertSame( InstructorRole::LEAD, $user_8->role_in_course );
	}

	public function test_on_save_post_promotes_existing_co_instructor_to_lead(): void {
		$this->instructor_service->add_instructor(
			InstructorEntityType::COURSE,
			500,
			7,
			InstructorRole::CO_INSTRUCTOR,
			1
		);

		$this->service->on_save_post( 500, $this->make_post( 500, 7 ), true );

		$assignment = $this->repo->find_assignment( InstructorEntityType::COURSE, 500, 7 );
		self::assertNotNull( $assignment );
		self::assertSame( InstructorRole::LEAD, $assignment->role_in_course );
		self::assertCount( 1, $this->repo->list_for_entity( InstructorEntityType::COURSE, 500 ) );
	}

	public function test_on_save_post_skips_autosave(): void {
		Functions\when( 'wp_is_post_autosave' )->justReturn( true );

		$this->service->on_save_post( 500, $this->make_post( 500, 7 ), false );

		self::assertSame( [], $this->repo->list_for_entity( InstructorEntityType::COURSE, 500 ) );
	}

	public function test_on_save_post_skips_revisions(): void {
		Functions\when( 'wp_is_post_revision' )->justReturn( true );

		$this->service->on_save_post( 500, $this->make_post( 500, 7 ), false );

		self::assertSame( [], $this->repo->list_for_entity( InstructorEntityType::COURSE, 500 ) );
	}

	public function test_on_save_post_skips_trashed_status(): void {
		$post = $this->make_post( 500, 7, 'vl_course', 'trash' );

		$this->service->on_save_post( 500, $post, true );

		self::assertSame( [], $this->repo->list_for_entity( InstructorEntityType::COURSE, 500 ) );
	}

	public function test_on_save_post_skips_auto_draft_status(): void {
		$post = $this->make_post( 500, 7, 'vl_course', 'auto-draft' );

		$this->service->on_save_post( 500, $post, false );

		self::assertSame( [], $this->repo->list_for_entity( InstructorEntityType::COURSE, 500 ) );
	}

	public function test_on_save_post_no_ops_for_non_course_non_webinar_post_types(): void {
		$post = $this->make_post( 500, 7, 'post', 'publish' );

		$this->service->on_save_post( 500, $post, true );

		self::assertSame( [], $this->repo->list_for_entity( InstructorEntityType::COURSE, 500 ) );
	}

	public function test_on_save_post_handles_vl_webinar(): void {
		$post = $this->make_post( 777, 7, 'vl_webinar' );

		$this->service->on_save_post( 777, $post, false );

		$assignment = $this->repo->find_assignment( InstructorEntityType::WEBINAR, 777, 7 );
		self::assertNotNull( $assignment );
		self::assertSame( InstructorRole::LEAD, $assignment->role_in_course );
	}

	public function test_on_post_deleted_drops_all_assignments_for_course(): void {
		$this->instructor_service->add_instructor( InstructorEntityType::COURSE, 500, 7, InstructorRole::LEAD, 1 );
		$this->instructor_service->add_instructor( InstructorEntityType::COURSE, 500, 8, InstructorRole::CO_INSTRUCTOR, 1 );

		$this->service->on_post_deleted( 500, $this->make_post( 500, 7 ) );

		self::assertSame( [], $this->repo->list_for_entity( InstructorEntityType::COURSE, 500 ) );
	}

	public function test_on_post_deleted_is_noop_for_non_course_non_webinar(): void {
		$this->instructor_service->add_instructor( InstructorEntityType::COURSE, 500, 7, InstructorRole::LEAD, 1 );
		$post = $this->make_post( 500, 7, 'post' );

		$this->service->on_post_deleted( 500, $post );

		self::assertCount( 1, $this->repo->list_for_entity( InstructorEntityType::COURSE, 500 ) );
	}

	public function test_on_save_post_ignores_zero_author(): void {
		$post = $this->make_post( 500, 0 );

		$this->service->on_save_post( 500, $post, false );

		self::assertSame( [], $this->repo->list_for_entity( InstructorEntityType::COURSE, 500 ) );
	}
}
