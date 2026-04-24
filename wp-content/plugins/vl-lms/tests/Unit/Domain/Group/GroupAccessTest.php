<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Domain\Group;

use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Group\AccessEntityType;
use VL\LMS\Domain\Group\AccessType;
use VL\LMS\Domain\Group\GroupAccess;

final class GroupAccessTest extends TestCase {

	/**
	 * @return array<string, mixed>
	 */
	private static function sample_row(): array {
		return [
			'id'          => '9',
			'group_id'    => '42',
			'entity_type' => 'course',
			'entity_id'   => '123',
			'access_type' => 'granted',
			'granted_at'  => '2026-04-23 10:00:00',
			'granted_by'  => '1',
			'expires_at'  => null,
		];
	}

	public function test_from_row_builds_object_from_complete_row(): void {
		$row                = self::sample_row();
		$row['expires_at']  = '2027-04-23 10:00:00';
		$row['access_type'] = 'purchased_by_org';

		$access = GroupAccess::from_row( $row );

		self::assertSame( 9, $access->id );
		self::assertSame( 42, $access->group_id );
		self::assertSame( AccessEntityType::COURSE, $access->entity_type );
		self::assertSame( 123, $access->entity_id );
		self::assertSame( AccessType::PURCHASED_BY_ORG, $access->access_type );
		self::assertSame( 1, $access->granted_by );
		self::assertSame( '2027-04-23 10:00:00', $access->expires_at );
	}

	public function test_from_row_coerces_numeric_strings_to_int(): void {
		$access = GroupAccess::from_row( self::sample_row() );

		self::assertIsInt( $access->id );
		self::assertIsInt( $access->group_id );
		self::assertIsInt( $access->entity_id );
		self::assertIsInt( $access->granted_by );
	}

	public function test_from_row_preserves_null_expires_at(): void {
		$access = GroupAccess::from_row( self::sample_row() );

		self::assertNull( $access->expires_at );
	}

	public function test_from_row_rejects_unknown_entity_type(): void {
		$row                = self::sample_row();
		$row['entity_type'] = 'lesson';

		$this->expectException( \InvalidArgumentException::class );

		GroupAccess::from_row( $row );
	}

	public function test_from_row_rejects_unknown_access_type(): void {
		$row                = self::sample_row();
		$row['access_type'] = 'gifted';

		$this->expectException( \InvalidArgumentException::class );

		GroupAccess::from_row( $row );
	}
}
