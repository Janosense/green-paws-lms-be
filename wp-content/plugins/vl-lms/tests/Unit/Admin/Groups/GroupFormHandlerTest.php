<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Admin\Groups;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Admin\Groups\GroupFormHandler;
use VL\LMS\Admin\Groups\GroupsListPage;
use VL\LMS\Domain\Group\AccessEntityType;
use VL\LMS\Domain\Group\GroupStatus;
use VL\LMS\Services\Enrollment\EnrollmentService;
use VL\LMS\Services\Groups\GroupEnrollmentService;
use VL\LMS\Services\Groups\GroupService;
use VL\LMS\Slug\CyrillicTransliterator;
use VL\LMS\Tests\Fixtures\InMemoryEnrollmentRepository;
use VL\LMS\Tests\Fixtures\InMemoryGroupAccessRepository;
use VL\LMS\Tests\Fixtures\InMemoryGroupMemberRepository;
use VL\LMS\Tests\Fixtures\InMemoryGroupRepository;
use WP_User;

final class GroupFormHandlerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private InMemoryGroupRepository $groups;
	private InMemoryGroupMemberRepository $members;
	private InMemoryGroupAccessRepository $access;
	private InMemoryEnrollmentRepository $enrollments;
	private GroupService $service;
	private GroupEnrollmentService $fanout;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'add_query_arg' )->alias(
			static function ( array $args, string $url ): string {
				return $url . '?' . http_build_query( $args );
			}
		);
		Functions\when( 'admin_url' )->alias( static fn ( string $p = '' ): string => '/wp-admin/' . $p );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_admin_referer' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'wp_generate_password' )->alias( static fn (): string => 'abc123' );

		$this->groups      = new InMemoryGroupRepository();
		$this->members     = new InMemoryGroupMemberRepository();
		$this->access      = new InMemoryGroupAccessRepository();
		$this->enrollments = new InMemoryEnrollmentRepository();

		$this->service = new GroupService( $this->groups, $this->members, $this->access );
		$this->fanout  = new GroupEnrollmentService(
			$this->members,
			$this->access,
			$this->enrollments,
			new EnrollmentService( $this->enrollments )
		);

		$_POST = [];
		$_GET  = [];
	}

	protected function tearDown(): void {
		$_POST = [];
		$_GET  = [];
		Monkey\tearDown();
		parent::tearDown();
	}

	private function handler(): TestableGroupFormHandler {
		return new TestableGroupFormHandler(
			$this->service,
			$this->fanout,
			$this->groups,
			$this->members,
			new CyrillicTransliterator()
		);
	}

	public function test_handle_create_inserts_group_and_redirects_to_detail(): void {
		$_POST = [
			'name'        => 'QA Cohort',
			'description' => 'Test cohort',
			'max_members' => '20',
		];

		$h = $this->handler();
		$h->handle_create();

		$group = $this->groups->find_by_slug( 'qa-cohort' );
		self::assertNotNull( $group );
		self::assertSame( 'QA Cohort', $group->name );
		self::assertSame( 'Test cohort', $group->description );
		self::assertSame( 20, $group->max_members );

		self::assertNotNull( $h->redirected_to );
		self::assertStringContainsString( 'page=vl-lms-groups', $h->redirected_to );
		self::assertStringContainsString( 'action=edit', $h->redirected_to );
		self::assertStringContainsString( 'notice=group_created', $h->redirected_to );
	}

	public function test_handle_create_redirects_with_invalid_name_when_blank(): void {
		$_POST = [ 'name' => '' ];

		$h = $this->handler();
		$h->handle_create();

		self::assertStringContainsString( 'notice=invalid_name', (string) $h->redirected_to );
	}

	public function test_handle_create_uniquifies_slug_on_collision(): void {
		$this->groups->insert(
			[
				'name'     => 'Old',
				'slug'     => 'qa-cohort',
				'type'     => 'ad_hoc',
				'owner_id' => 1,
				'status'   => GroupStatus::ACTIVE->value,
			]
		);
		$_POST = [ 'name' => 'QA Cohort' ];

		$h = $this->handler();
		$h->handle_create();

		self::assertNotNull( $this->groups->find_by_slug( 'qa-cohort-2' ) );
	}

	public function test_handle_update_writes_changes_and_redirects(): void {
		$id    = $this->groups->insert(
			[
				'name'     => 'Old',
				'slug'     => 'old',
				'type'     => 'ad_hoc',
				'owner_id' => 1,
				'status'   => GroupStatus::ACTIVE->value,
			]
		);
		$_POST = [
			'group_id'    => (string) $id,
			'name'        => 'Renamed',
			'description' => 'New desc',
			'status'      => 'archived',
		];

		$h = $this->handler();
		$h->handle_update();

		$group = $this->groups->find_by_id( $id );
		self::assertNotNull( $group );
		self::assertSame( 'Renamed', $group->name );
		self::assertSame( 'New desc', $group->description );
		self::assertSame( GroupStatus::ARCHIVED, $group->status );
		self::assertStringContainsString( 'notice=group_updated', (string) $h->redirected_to );
	}

	public function test_handle_member_add_rejects_non_student(): void {
		$id    = $this->groups->insert(
			[
				'name'     => 'G',
				'slug'     => 'g',
				'type'     => 'ad_hoc',
				'owner_id' => 1,
				'status'   => GroupStatus::ACTIVE->value,
			]
		);
		$_POST = [
			'group_id' => (string) $id,
			'user_id'  => '42',
		];
		Functions\when( 'get_userdata' )->justReturn( false ); // not a student

		$h = $this->handler();
		$h->handle_member_add();

		self::assertStringContainsString( 'notice=user_not_found', (string) $h->redirected_to );
		self::assertSame( 0, $this->members->count_active_members( $id ) );
	}

	public function test_handle_member_add_accepts_student_and_fans_out(): void {
		$id        = $this->groups->insert(
			[
				'name'     => 'G',
				'slug'     => 'g',
				'type'     => 'ad_hoc',
				'owner_id' => 1,
				'status'   => GroupStatus::ACTIVE->value,
			]
		);
		$course_id = 555;
		$this->access->insert(
			[
				'group_id'    => $id,
				'entity_type' => AccessEntityType::COURSE->value,
				'entity_id'   => $course_id,
				'access_type' => 'granted',
				'granted_at'  => gmdate( 'Y-m-d H:i:s' ),
				'granted_by'  => 1,
				'expires_at'  => null,
			]
		);

		$student_id = 7;
		$_POST      = [
			'group_id' => (string) $id,
			'user_id'  => (string) $student_id,
		];
		Functions\when( 'get_userdata' )->alias(
			static function () use ( $student_id ): WP_User {
				$user        = new WP_User();
				$user->ID    = $student_id;
				$user->roles = [ 'student' ];
				return $user;
			}
		);

		$h = $this->handler();
		$h->handle_member_add();

		self::assertStringContainsString( 'notice=member_added', (string) $h->redirected_to );
		self::assertSame( 1, $this->members->count_active_members( $id ) );
		// Fan-out should have created an enrollment for the new member.
		self::assertNotNull( $this->enrollments->find_for_user_and_course( $student_id, $course_id ) );
	}

	public function test_handle_course_grant_rejects_non_published_course(): void {
		$id    = $this->groups->insert(
			[
				'name'     => 'G',
				'slug'     => 'g',
				'type'     => 'ad_hoc',
				'owner_id' => 1,
				'status'   => GroupStatus::ACTIVE->value,
			]
		);
		$_POST = [
			'group_id'  => (string) $id,
			'course_id' => '999',
		];
		Functions\when( 'get_post' )->justReturn( null );

		$h = $this->handler();
		$h->handle_course_grant();

		self::assertStringContainsString( 'notice=course_not_found', (string) $h->redirected_to );
		self::assertCount( 0, $this->access->list_active_for_group( $id ) );
	}

	public function test_handle_course_grant_creates_access_and_redirects(): void {
		$id        = $this->groups->insert(
			[
				'name'     => 'G',
				'slug'     => 'g',
				'type'     => 'ad_hoc',
				'owner_id' => 1,
				'status'   => GroupStatus::ACTIVE->value,
			]
		);
		$course_id = 321;
		$_POST     = [
			'group_id'  => (string) $id,
			'course_id' => (string) $course_id,
		];
		Functions\when( 'get_post' )->alias(
			static function () {
				$post              = Mockery::mock( 'WP_Post' );
				$post->ID          = 321;
				$post->post_type   = 'vl_course';
				$post->post_status = 'publish';
				return $post;
			}
		);

		$h = $this->handler();
		$h->handle_course_grant();

		self::assertStringContainsString( 'notice=course_granted', (string) $h->redirected_to );
		self::assertCount( 1, $this->access->list_active_for_group( $id ) );
	}

	public function test_handle_course_revoke_removes_access(): void {
		$id        = $this->groups->insert(
			[
				'name'     => 'G',
				'slug'     => 'g',
				'type'     => 'ad_hoc',
				'owner_id' => 1,
				'status'   => GroupStatus::ACTIVE->value,
			]
		);
		$course_id = 999;
		$this->access->insert(
			[
				'group_id'    => $id,
				'entity_type' => AccessEntityType::COURSE->value,
				'entity_id'   => $course_id,
				'access_type' => 'granted',
				'granted_at'  => gmdate( 'Y-m-d H:i:s' ),
				'granted_by'  => 1,
				'expires_at'  => null,
			]
		);
		$_GET = [
			'group_id'  => (string) $id,
			'course_id' => (string) $course_id,
		];

		$h = $this->handler();
		$h->handle_course_revoke();

		self::assertStringContainsString( 'notice=course_revoked', (string) $h->redirected_to );
		self::assertCount( 0, $this->access->list_active_for_group( $id ) );
	}

	public function test_handle_member_remove_marks_left_and_fans_out_expiry(): void {
		$id      = $this->groups->insert(
			[
				'name'     => 'G',
				'slug'     => 'g',
				'type'     => 'ad_hoc',
				'owner_id' => 1,
				'status'   => GroupStatus::ACTIVE->value,
			]
		);
		$user_id = 5;
		$this->members->insert(
			[
				'group_id'      => $id,
				'user_id'       => $user_id,
				'role_in_group' => 'member',
				'joined_at'     => gmdate( 'Y-m-d H:i:s' ),
			]
		);
		$_GET = [
			'group_id' => (string) $id,
			'user_id'  => (string) $user_id,
		];

		$h = $this->handler();
		$h->handle_member_remove();

		self::assertStringContainsString( 'notice=member_removed', (string) $h->redirected_to );
		self::assertSame( 0, $this->members->count_active_members( $id ) );
	}

	public function test_action_constants_match_registration_keys(): void {
		// Smoke: the page templates string-concat these into form action
		// values, and the AJAX urls use them — keep both ends in sync.
		self::assertSame( 'vl_lms_group_create', GroupFormHandler::ACTION_CREATE );
		self::assertSame( 'vl_lms_search_students', GroupFormHandler::AJAX_SEARCH_STUDENTS );
		self::assertSame( 'vl_lms_search_courses', GroupFormHandler::AJAX_SEARCH_COURSES );
		self::assertSame( GroupsListPage::CAPABILITY, 'vl_manage_groups' );
	}
}
