<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Domain\Group;

use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Group\GroupMember;
use VL\LMS\Domain\Group\MemberRole;

final class GroupMemberTest extends TestCase {

	/**
	 * @return array<string, mixed>
	 */
	private static function sample_row(): array {
		return [
			'id'            => '5',
			'group_id'      => '42',
			'user_id'       => '7',
			'role_in_group' => 'member',
			'joined_at'     => '2026-04-23 10:00:00',
			'left_at'       => null,
		];
	}

	public function test_from_row_builds_object_from_complete_row(): void {
		$row            = self::sample_row();
		$row['left_at'] = '2026-05-01 09:00:00';

		$member = GroupMember::from_row( $row );

		self::assertSame( 5, $member->id );
		self::assertSame( 42, $member->group_id );
		self::assertSame( 7, $member->user_id );
		self::assertSame( MemberRole::MEMBER, $member->role_in_group );
		self::assertSame( '2026-04-23 10:00:00', $member->joined_at );
		self::assertSame( '2026-05-01 09:00:00', $member->left_at );
	}

	public function test_from_row_coerces_numeric_strings_to_int(): void {
		$member = GroupMember::from_row( self::sample_row() );

		self::assertIsInt( $member->id );
		self::assertIsInt( $member->group_id );
		self::assertIsInt( $member->user_id );
	}

	public function test_from_row_preserves_null_left_at(): void {
		$member = GroupMember::from_row( self::sample_row() );

		self::assertNull( $member->left_at );
	}

	public function test_is_active_true_when_left_at_null(): void {
		$member = GroupMember::from_row( self::sample_row() );

		self::assertTrue( $member->is_active() );
	}

	public function test_is_active_false_when_left_at_set(): void {
		$row            = self::sample_row();
		$row['left_at'] = '2026-05-01 09:00:00';

		$member = GroupMember::from_row( $row );

		self::assertFalse( $member->is_active() );
	}

	public function test_from_row_rejects_unknown_role(): void {
		$row                  = self::sample_row();
		$row['role_in_group'] = 'owner';

		$this->expectException( \InvalidArgumentException::class );

		GroupMember::from_row( $row );
	}
}
