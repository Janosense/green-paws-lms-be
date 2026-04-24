<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Domain\Group;

use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Group\Group;
use VL\LMS\Domain\Group\GroupStatus;
use VL\LMS\Domain\Group\GroupType;

final class GroupTest extends TestCase {

	/**
	 * @return array<string, mixed>
	 */
	private static function sample_row(): array {
		return [
			'id'          => '42',
			'name'        => 'Test Clinic',
			'slug'        => 'test-clinic',
			'description' => null,
			'type'        => 'organization',
			'owner_id'    => '7',
			'max_members' => null,
			'status'      => 'active',
			'created_at'  => '2026-04-23 10:00:00',
			'updated_at'  => '2026-04-23 10:00:00',
		];
	}

	public function test_from_row_builds_object_from_complete_row(): void {
		$row                = self::sample_row();
		$row['description'] = 'Mobile-vet company';
		$row['max_members'] = '25';

		$group = Group::from_row( $row );

		self::assertSame( 42, $group->id );
		self::assertSame( 'Test Clinic', $group->name );
		self::assertSame( 'test-clinic', $group->slug );
		self::assertSame( 'Mobile-vet company', $group->description );
		self::assertSame( GroupType::ORGANIZATION, $group->type );
		self::assertSame( 7, $group->owner_id );
		self::assertSame( 25, $group->max_members );
		self::assertSame( GroupStatus::ACTIVE, $group->status );
	}

	public function test_from_row_coerces_numeric_strings_to_int(): void {
		$group = Group::from_row( self::sample_row() );

		self::assertIsInt( $group->id );
		self::assertIsInt( $group->owner_id );
	}

	public function test_from_row_preserves_null_in_nullable_fields(): void {
		$group = Group::from_row( self::sample_row() );

		self::assertNull( $group->description );
		self::assertNull( $group->max_members );
	}

	public function test_from_row_rejects_unknown_type(): void {
		$row         = self::sample_row();
		$row['type'] = 'bogus';

		$this->expectException( \InvalidArgumentException::class );

		Group::from_row( $row );
	}

	public function test_from_row_rejects_unknown_status(): void {
		$row           = self::sample_row();
		$row['status'] = 'frozen';

		$this->expectException( \InvalidArgumentException::class );

		Group::from_row( $row );
	}
}
