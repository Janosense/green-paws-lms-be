<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Services\Groups;

use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Group\AccessEntityType;
use VL\LMS\Domain\Group\GroupStatus;
use VL\LMS\Domain\Group\GroupType;
use VL\LMS\Domain\Group\MemberRole;
use VL\LMS\Services\Groups\GroupService;
use VL\LMS\Tests\Fixtures\InMemoryGroupAccessRepository;
use VL\LMS\Tests\Fixtures\InMemoryGroupMemberRepository;
use VL\LMS\Tests\Fixtures\InMemoryGroupRepository;

final class GroupServiceTest extends TestCase {

	private InMemoryGroupRepository $groups;

	private InMemoryGroupMemberRepository $members;

	private InMemoryGroupAccessRepository $access;

	private GroupService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->groups  = new InMemoryGroupRepository();
		$this->members = new InMemoryGroupMemberRepository();
		$this->access  = new InMemoryGroupAccessRepository();
		$this->service = new GroupService( $this->groups, $this->members, $this->access );
	}

	public function test_create_group_inserts_and_returns_group(): void {
		$group = $this->service->create_group( 'Test Clinic', 'test-clinic', 7, GroupType::ORGANIZATION );

		self::assertSame( 'Test Clinic', $group->name );
		self::assertSame( 'test-clinic', $group->slug );
		self::assertSame( 7, $group->owner_id );
		self::assertSame( GroupType::ORGANIZATION, $group->type );
		self::assertSame( GroupStatus::ACTIVE, $group->status );
	}

	public function test_create_group_throws_on_empty_name(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->service->create_group( '   ', 'test-clinic', 7 );
	}

	public function test_create_group_throws_on_empty_slug(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->service->create_group( 'Test Clinic', '', 7 );
	}

	public function test_create_group_throws_on_duplicate_slug(): void {
		$this->service->create_group( 'First', 'dup', 7 );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'slug "dup"' );

		$this->service->create_group( 'Second', 'dup', 7 );
	}

	public function test_archive_group_flips_status(): void {
		$group = $this->service->create_group( 'Clinic', 'clinic', 7 );

		$archived = $this->service->archive_group( $group->id );

		self::assertSame( GroupStatus::ARCHIVED, $archived->status );
	}

	public function test_archive_group_throws_when_missing(): void {
		$this->expectException( \RuntimeException::class );
		$this->service->archive_group( 9999 );
	}

	public function test_rename_group_updates_name(): void {
		$group = $this->service->create_group( 'Old', 'clinic', 7 );

		$renamed = $this->service->rename_group( $group->id, 'New' );

		self::assertSame( 'New', $renamed->name );
	}

	public function test_rename_group_rejects_empty_name(): void {
		$group = $this->service->create_group( 'Old', 'clinic', 7 );

		$this->expectException( \InvalidArgumentException::class );

		$this->service->rename_group( $group->id, '   ' );
	}

	public function test_add_member_inserts_new_membership(): void {
		$group = $this->service->create_group( 'Clinic', 'clinic', 7 );

		$member = $this->service->add_member( $group->id, 42 );

		self::assertSame( 42, $member->user_id );
		self::assertSame( $group->id, $member->group_id );
		self::assertTrue( $member->is_active() );
	}

	public function test_add_member_is_idempotent_for_active_membership(): void {
		$group  = $this->service->create_group( 'Clinic', 'clinic', 7 );
		$first  = $this->service->add_member( $group->id, 42 );
		$second = $this->service->add_member( $group->id, 42 );

		self::assertSame( $first->id, $second->id );
		self::assertSame( 1, $this->members->count_active_members( $group->id ) );
	}

	public function test_add_member_respects_max_members(): void {
		$group = $this->service->create_group( 'Clinic', 'clinic', 7, GroupType::AD_HOC, null, 2 );
		$this->service->add_member( $group->id, 42 );
		$this->service->add_member( $group->id, 43 );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'at capacity' );

		$this->service->add_member( $group->id, 44 );
	}

	public function test_add_member_can_store_manager_role(): void {
		$group = $this->service->create_group( 'Clinic', 'clinic', 7 );

		$member = $this->service->add_member( $group->id, 42, MemberRole::MANAGER );

		self::assertSame( MemberRole::MANAGER, $member->role_in_group );
	}

	public function test_remove_member_marks_membership_left(): void {
		$group = $this->service->create_group( 'Clinic', 'clinic', 7 );
		$this->service->add_member( $group->id, 42 );

		$removed = $this->service->remove_member( $group->id, 42 );

		self::assertNotNull( $removed );
		self::assertFalse( $removed->is_active() );
		self::assertNull( $this->members->find_active( $group->id, 42 ) );
	}

	public function test_remove_member_returns_null_when_no_active_membership(): void {
		$group = $this->service->create_group( 'Clinic', 'clinic', 7 );

		self::assertNull( $this->service->remove_member( $group->id, 42 ) );
	}

	public function test_remove_member_is_idempotent(): void {
		$group = $this->service->create_group( 'Clinic', 'clinic', 7 );
		$this->service->add_member( $group->id, 42 );

		$this->service->remove_member( $group->id, 42 );
		$second = $this->service->remove_member( $group->id, 42 );

		self::assertNull( $second );
	}

	public function test_grant_access_inserts_new_row(): void {
		$group = $this->service->create_group( 'Clinic', 'clinic', 7 );

		$access = $this->service->grant_access( $group->id, AccessEntityType::COURSE, 123, 7 );

		self::assertSame( $group->id, $access->group_id );
		self::assertSame( 123, $access->entity_id );
		self::assertSame( AccessEntityType::COURSE, $access->entity_type );
	}

	public function test_grant_access_is_idempotent(): void {
		$group  = $this->service->create_group( 'Clinic', 'clinic', 7 );
		$first  = $this->service->grant_access( $group->id, AccessEntityType::COURSE, 123, 7 );
		$second = $this->service->grant_access( $group->id, AccessEntityType::COURSE, 123, 7 );

		self::assertSame( $first->id, $second->id );
		self::assertCount( 1, $this->access->list_by_group( $group->id ) );
	}

	public function test_revoke_access_removes_row_and_returns_pre_delete_object(): void {
		$group  = $this->service->create_group( 'Clinic', 'clinic', 7 );
		$access = $this->service->grant_access( $group->id, AccessEntityType::COURSE, 123, 7 );

		$revoked = $this->service->revoke_access( $group->id, AccessEntityType::COURSE, 123 );

		self::assertNotNull( $revoked );
		self::assertSame( $access->id, $revoked->id );
		self::assertNull( $this->access->find_by_id( $access->id ) );
	}

	public function test_revoke_access_returns_null_when_no_row_exists(): void {
		$group = $this->service->create_group( 'Clinic', 'clinic', 7 );

		self::assertNull( $this->service->revoke_access( $group->id, AccessEntityType::COURSE, 999 ) );
	}
}
