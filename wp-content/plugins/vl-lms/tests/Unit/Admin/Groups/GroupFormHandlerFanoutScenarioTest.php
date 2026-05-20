<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Admin\Groups;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Enrollment\EnrollmentSource;
use VL\LMS\Domain\Enrollment\EnrollmentStatus;
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

/**
 * End-to-end fan-out scenario exercised through the admin form-handler.
 *
 * Drives `GroupFormHandler` with real {@see GroupService} +
 * {@see GroupEnrollmentService} + {@see EnrollmentService}, backed by
 * in-memory repository fixtures. Verifies the documented contract:
 *
 *   add member → grant access → 1 enrollment per member
 *   add 2nd member after access exists → 2 enrollments total
 *   remove 1 member → only that user's enrollment expires
 *   revoke access → every remaining member's enrollment expires
 *
 * The codebase has no live-`$wpdb` integration harness; this scenario
 * lives next to {@see GroupFormHandlerTest} and reaches the same
 * coverage by composing real services over in-memory repos (same
 * pattern as {@see \VL\LMS\Tests\Unit\Services\Groups\GroupEnrollmentServiceTest}).
 */
final class GroupFormHandlerFanoutScenarioTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private InMemoryGroupRepository $groups;
	private InMemoryGroupMemberRepository $members;
	private InMemoryGroupAccessRepository $access;
	private InMemoryEnrollmentRepository $enrollments;
	private TestableGroupFormHandler $handler;

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

		$this->groups      = new InMemoryGroupRepository();
		$this->members     = new InMemoryGroupMemberRepository();
		$this->access      = new InMemoryGroupAccessRepository();
		$this->enrollments = new InMemoryEnrollmentRepository();

		$service = new GroupService( $this->groups, $this->members, $this->access );
		$fanout  = new GroupEnrollmentService(
			$this->members,
			$this->access,
			$this->enrollments,
			new EnrollmentService( $this->enrollments )
		);

		$this->handler = new TestableGroupFormHandler(
			$service,
			$fanout,
			$this->groups,
			$this->members,
			new CyrillicTransliterator()
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

	public function test_full_lifecycle_member_add_grant_remove_revoke(): void {
		$group_id  = $this->seed_group();
		$course_id = 555;
		$student_a = 11;
		$student_b = 22;

		$this->stub_get_userdata_for_students( [ $student_a, $student_b ] );
		$this->stub_published_course( $course_id );

		// 1) Grant access first, with no members → no enrollments.
		$_POST = [
			'group_id'  => (string) $group_id,
			'course_id' => (string) $course_id,
		];
		$this->handler->handle_course_grant();
		self::assertNull( $this->enrollments->find_for_user_and_course( $student_a, $course_id ) );

		// 2) Add student A → fan-out creates an active enrollment for A.
		$_POST = [
			'group_id' => (string) $group_id,
			'user_id'  => (string) $student_a,
		];
		$this->handler->handle_member_add();
		$a_enrol = $this->enrollments->find_for_user_and_course( $student_a, $course_id );
		self::assertNotNull( $a_enrol );
		self::assertSame( EnrollmentStatus::ACTIVE, $a_enrol->status );
		self::assertSame( EnrollmentSource::GROUP, $a_enrol->source );
		self::assertSame( $group_id, $a_enrol->source_group_id );

		// 3) Add student B → second enrollment created; A untouched.
		$_POST = [
			'group_id' => (string) $group_id,
			'user_id'  => (string) $student_b,
		];
		$this->handler->handle_member_add();
		self::assertNotNull( $this->enrollments->find_for_user_and_course( $student_b, $course_id ) );
		self::assertNull(
			$this->enrollments->find_for_user_and_course( $student_a, $course_id )->expires_at,
			'Adding B must not touch A.'
		);

		// 4) Remove student B → B expires, A still active.
		$_GET = [
			'group_id' => (string) $group_id,
			'user_id'  => (string) $student_b,
		];
		$this->handler->handle_member_remove();

		$b_after_remove = $this->enrollments->find_for_user_and_course( $student_b, $course_id );
		self::assertNotNull( $b_after_remove );
		self::assertNotNull( $b_after_remove->expires_at, 'B enrollment must have expires_at set.' );

		$a_after_b_removed = $this->enrollments->find_for_user_and_course( $student_a, $course_id );
		self::assertNotNull( $a_after_b_removed );
		self::assertNull( $a_after_b_removed->expires_at, 'A enrollment must survive B removal.' );

		// 5) Revoke course access → every remaining member's enrollment expires.
		$_GET = [
			'group_id'  => (string) $group_id,
			'course_id' => (string) $course_id,
		];
		$this->handler->handle_course_revoke();

		$a_after_revoke = $this->enrollments->find_for_user_and_course( $student_a, $course_id );
		self::assertNotNull( $a_after_revoke );
		self::assertNotNull( $a_after_revoke->expires_at, 'A enrollment must expire on access revoke.' );
		self::assertCount( 0, $this->access->list_active_for_group( $group_id ) );
	}

	public function test_max_members_capacity_blocks_third_join_and_blocks_fan_out(): void {
		$group_id  = $this->seed_group( max_members: 2 );
		$course_id = 777;
		$student_a = 11;
		$student_b = 22;
		$student_c = 33;

		$this->stub_get_userdata_for_students( [ $student_a, $student_b, $student_c ] );
		$this->stub_published_course( $course_id );

		// Pre-grant access so adding members fans out.
		$_POST = [
			'group_id'  => (string) $group_id,
			'course_id' => (string) $course_id,
		];
		$this->handler->handle_course_grant();

		foreach ( [ $student_a, $student_b ] as $uid ) {
			$_POST = [
				'group_id' => (string) $group_id,
				'user_id'  => (string) $uid,
			];
			$this->handler->handle_member_add();
		}

		// Third add must hit capacity_full and never create an enrollment for C.
		$_POST = [
			'group_id' => (string) $group_id,
			'user_id'  => (string) $student_c,
		];
		$this->handler->handle_member_add();

		self::assertStringContainsString( 'notice=capacity_full', (string) $this->handler->redirected_to );
		self::assertNull( $this->enrollments->find_for_user_and_course( $student_c, $course_id ) );
		self::assertSame( 2, $this->members->count_active_members( $group_id ) );
	}

	private function seed_group( int $max_members = 0 ): int {
		$data = [
			'name'     => 'Cohort',
			'slug'     => 'cohort',
			'type'     => 'ad_hoc',
			'owner_id' => 1,
			'status'   => GroupStatus::ACTIVE->value,
		];
		if ( $max_members > 0 ) {
			$data['max_members'] = $max_members;
		}
		return $this->groups->insert( $data );
	}

	/**
	 * @param list<int> $student_ids
	 */
	private function stub_get_userdata_for_students( array $student_ids ): void {
		Functions\when( 'get_userdata' )->alias(
			static function ( int $id ) use ( $student_ids ): WP_User|false {
				if ( ! in_array( $id, $student_ids, true ) ) {
					return false;
				}
				$user        = new WP_User();
				$user->ID    = $id;
				$user->roles = [ 'student' ];
				return $user;
			}
		);
	}

	private function stub_published_course( int $course_id ): void {
		Functions\when( 'get_post' )->alias(
			static function ( int $id ) use ( $course_id ) {
				if ( $id !== $course_id ) {
					return null;
				}
				$post              = Mockery::mock( 'WP_Post' );
				$post->ID          = $course_id;
				$post->post_type   = 'vl_course';
				$post->post_status = 'publish';
				return $post;
			}
		);
	}
}
