<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Services\Groups;

use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Enrollment\EnrollmentSource;
use VL\LMS\Domain\Enrollment\EnrollmentStatus;
use VL\LMS\Domain\Group\AccessEntityType;
use VL\LMS\Domain\Group\GroupAccess;
use VL\LMS\Domain\Group\GroupMember;
use VL\LMS\Domain\Group\GroupType;
use VL\LMS\Services\Enrollment\EnrollmentService;
use VL\LMS\Services\Groups\GroupEnrollmentService;
use VL\LMS\Services\Groups\GroupService;
use VL\LMS\Tests\Fixtures\InMemoryEnrollmentRepository;
use VL\LMS\Tests\Fixtures\InMemoryGroupAccessRepository;
use VL\LMS\Tests\Fixtures\InMemoryGroupMemberRepository;
use VL\LMS\Tests\Fixtures\InMemoryGroupRepository;

final class GroupEnrollmentServiceTest extends TestCase {

	private InMemoryGroupRepository $groups;

	private InMemoryGroupMemberRepository $members;

	private InMemoryGroupAccessRepository $access;

	private InMemoryEnrollmentRepository $enrollments;

	private EnrollmentService $enrollment_service;

	private GroupService $group_service;

	private GroupEnrollmentService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->groups             = new InMemoryGroupRepository();
		$this->members            = new InMemoryGroupMemberRepository();
		$this->access             = new InMemoryGroupAccessRepository();
		$this->enrollments        = new InMemoryEnrollmentRepository();
		$this->enrollment_service = new EnrollmentService( $this->enrollments );
		$this->group_service      = new GroupService( $this->groups, $this->members, $this->access );
		$this->service            = new GroupEnrollmentService(
			$this->members,
			$this->access,
			$this->enrollments,
			$this->enrollment_service
		);
	}

	private function setup_group_with_members( int $member_count ): int {
		$group = $this->group_service->create_group( 'Clinic', 'clinic', 1, GroupType::ORGANIZATION );
		for ( $i = 2; $i < 2 + $member_count; $i++ ) {
			$this->group_service->add_member( $group->id, $i );
		}
		return $group->id;
	}

	public function test_on_access_granted_creates_one_enrollment_per_active_member(): void {
		$group_id = $this->setup_group_with_members( 3 );
		$access   = $this->group_service->grant_access( $group_id, AccessEntityType::COURSE, 500, 1 );

		$this->service->on_access_granted( $access );

		$enrollments = $this->enrollments->list_for_course( 500 );
		self::assertCount( 3, $enrollments );
		foreach ( $enrollments as $enrollment ) {
			self::assertSame( EnrollmentSource::GROUP, $enrollment->source );
			self::assertSame( $group_id, $enrollment->source_group_id );
			self::assertSame( EnrollmentStatus::ACTIVE, $enrollment->status );
		}
	}

	public function test_on_access_granted_does_not_fan_out_webinar_entities(): void {
		$group_id       = $this->setup_group_with_members( 2 );
		$webinar_access = new GroupAccess(
			id:           99,
			group_id:     $group_id,
			entity_type:  AccessEntityType::WEBINAR,
			entity_id:    777,
			access_type:  \VL\LMS\Domain\Group\AccessType::GRANTED,
			granted_at:   '2026-04-23 10:00:00',
			granted_by:   1,
			expires_at:   null
		);

		$this->service->on_access_granted( $webinar_access );

		self::assertSame( [], $this->enrollments->list_for_course( 777 ) );
	}

	public function test_on_access_granted_with_no_active_members_is_noop(): void {
		$group  = $this->group_service->create_group( 'Empty', 'empty', 1 );
		$access = $this->group_service->grant_access( $group->id, AccessEntityType::COURSE, 500, 1 );

		$this->service->on_access_granted( $access );

		self::assertSame( [], $this->enrollments->list_for_course( 500 ) );
	}

	public function test_on_access_granted_does_not_overwrite_existing_manual_enrollment(): void {
		$group_id = $this->setup_group_with_members( 1 );
		$this->enrollment_service->enroll( 2, 500, EnrollmentSource::MANUAL );

		$access = $this->group_service->grant_access( $group_id, AccessEntityType::COURSE, 500, 1 );
		$this->service->on_access_granted( $access );

		$enrollment = $this->enrollments->find_for_user_and_course( 2, 500 );
		self::assertNotNull( $enrollment );
		self::assertSame( EnrollmentSource::MANUAL, $enrollment->source, 'Manual enrollments must not be overwritten by fan-out.' );
		self::assertNull( $enrollment->source_group_id );
	}

	public function test_on_member_added_enrolls_new_member_into_all_active_access_rows(): void {
		$group = $this->group_service->create_group( 'Clinic', 'clinic', 1 );
		$this->group_service->grant_access( $group->id, AccessEntityType::COURSE, 500, 1 );
		$this->group_service->grant_access( $group->id, AccessEntityType::COURSE, 501, 1 );

		$member = $this->group_service->add_member( $group->id, 42 );
		$this->service->on_member_added( $member );

		self::assertCount( 1, $this->enrollments->list_for_course( 500 ) );
		self::assertCount( 1, $this->enrollments->list_for_course( 501 ) );
	}

	public function test_on_member_added_with_no_active_access_is_noop(): void {
		$group  = $this->group_service->create_group( 'Clinic', 'clinic', 1 );
		$member = $this->group_service->add_member( $group->id, 42 );

		$this->service->on_member_added( $member );

		self::assertSame( [], $this->enrollments->list_for_user( 42 ) );
	}

	public function test_on_member_added_skips_webinar_access_rows(): void {
		$group = $this->group_service->create_group( 'Clinic', 'clinic', 1 );
		$this->access->insert(
			[
				'group_id'    => $group->id,
				'entity_type' => AccessEntityType::WEBINAR->value,
				'entity_id'   => 777,
				'access_type' => 'granted',
				'granted_by'  => 1,
			]
		);

		$member = $this->group_service->add_member( $group->id, 42 );
		$this->service->on_member_added( $member );

		self::assertSame( [], $this->enrollments->list_for_user( 42 ) );
	}

	public function test_on_member_removed_expires_group_sourced_enrollments(): void {
		$group_id = $this->setup_group_with_members( 1 );
		$access   = $this->group_service->grant_access( $group_id, AccessEntityType::COURSE, 500, 1 );
		$this->service->on_access_granted( $access );

		$this->service->on_member_removed( $group_id, 2 );

		$enrollment = $this->enrollments->find_for_user_and_course( 2, 500 );
		self::assertNotNull( $enrollment );
		self::assertNotNull( $enrollment->expires_at );
		self::assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $enrollment->expires_at );
	}

	public function test_on_member_removed_does_not_touch_manual_enrollments(): void {
		$group_id = $this->setup_group_with_members( 1 );
		$this->enrollment_service->enroll( 2, 500, EnrollmentSource::MANUAL );

		$this->service->on_member_removed( $group_id, 2 );

		$enrollment = $this->enrollments->find_for_user_and_course( 2, 500 );
		self::assertNotNull( $enrollment );
		self::assertSame( EnrollmentSource::MANUAL, $enrollment->source );
		self::assertNull( $enrollment->expires_at );
	}

	public function test_on_member_removed_does_not_touch_other_group_enrollments(): void {
		$group_a = $this->group_service->create_group( 'A', 'a', 1 );
		$group_b = $this->group_service->create_group( 'B', 'b', 1 );
		$this->group_service->add_member( $group_a->id, 42 );
		$this->group_service->add_member( $group_b->id, 42 );
		$this->service->on_access_granted(
			$this->group_service->grant_access( $group_a->id, AccessEntityType::COURSE, 500, 1 )
		);
		$this->service->on_access_granted(
			$this->group_service->grant_access( $group_b->id, AccessEntityType::COURSE, 501, 1 )
		);

		$this->service->on_member_removed( $group_a->id, 42 );

		$enrollment_a = $this->enrollments->find_for_user_and_course( 42, 500 );
		$enrollment_b = $this->enrollments->find_for_user_and_course( 42, 501 );
		self::assertNotNull( $enrollment_a );
		self::assertNotNull( $enrollment_b );
		self::assertNotNull( $enrollment_a->expires_at, 'Group A enrollment must be expired after leaving group A.' );
		self::assertNull( $enrollment_b->expires_at, 'Group B enrollment must remain untouched.' );
		self::assertSame( $group_b->id, $enrollment_b->source_group_id );
	}

	public function test_on_member_removed_does_not_change_status(): void {
		$group_id = $this->setup_group_with_members( 1 );
		$this->service->on_access_granted(
			$this->group_service->grant_access( $group_id, AccessEntityType::COURSE, 500, 1 )
		);

		$this->service->on_member_removed( $group_id, 2 );

		$enrollment = $this->enrollments->find_for_user_and_course( 2, 500 );
		self::assertNotNull( $enrollment );
		self::assertSame( EnrollmentStatus::ACTIVE, $enrollment->status );
	}

	public function test_on_access_revoked_expires_every_members_enrollment(): void {
		$group_id = $this->setup_group_with_members( 3 );
		$this->service->on_access_granted(
			$this->group_service->grant_access( $group_id, AccessEntityType::COURSE, 500, 1 )
		);

		$this->service->on_access_revoked( $group_id, AccessEntityType::COURSE, 500 );

		foreach ( $this->enrollments->list_for_course( 500 ) as $enrollment ) {
			self::assertNotNull( $enrollment->expires_at );
		}
	}

	public function test_on_access_revoked_is_noop_for_webinar_entity(): void {
		$group_id = $this->setup_group_with_members( 2 );
		$this->service->on_access_granted(
			$this->group_service->grant_access( $group_id, AccessEntityType::COURSE, 500, 1 )
		);

		$this->service->on_access_revoked( $group_id, AccessEntityType::WEBINAR, 500 );

		foreach ( $this->enrollments->list_for_course( 500 ) as $enrollment ) {
			self::assertNull( $enrollment->expires_at, 'Course enrollments must be untouched on a webinar revoke.' );
		}
	}

	public function test_on_member_removed_is_safe_to_call_twice(): void {
		$group_id = $this->setup_group_with_members( 1 );
		$this->service->on_access_granted(
			$this->group_service->grant_access( $group_id, AccessEntityType::COURSE, 500, 1 )
		);

		$this->service->on_member_removed( $group_id, 2 );
		$first = $this->enrollments->find_for_user_and_course( 2, 500 );

		$this->service->on_member_removed( $group_id, 2 );
		$second = $this->enrollments->find_for_user_and_course( 2, 500 );

		self::assertNotNull( $first );
		self::assertNotNull( $second );
		self::assertNotNull( $first->expires_at );
		self::assertNotNull( $second->expires_at );
	}
}
