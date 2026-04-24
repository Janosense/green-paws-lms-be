<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Domain\CourseInstructor;

use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\CourseInstructor\CourseInstructor;
use VL\LMS\Domain\CourseInstructor\InstructorEntityType;
use VL\LMS\Domain\CourseInstructor\InstructorRole;

final class CourseInstructorTest extends TestCase {

	/**
	 * @return array<string, mixed>
	 */
	private static function sample_row(): array {
		return [
			'id'             => '11',
			'entity_type'    => 'course',
			'entity_id'      => '123',
			'user_id'        => '7',
			'role_in_course' => 'lead',
			'display_order'  => '0',
			'assigned_at'    => '2026-04-23 10:00:00',
			'assigned_by'    => '1',
		];
	}

	public function test_from_row_builds_object_from_complete_row(): void {
		$row                   = self::sample_row();
		$row['role_in_course'] = 'co_instructor';
		$row['display_order']  = '3';
		$row['entity_type']    = 'webinar';

		$assignment = CourseInstructor::from_row( $row );

		self::assertSame( 11, $assignment->id );
		self::assertSame( InstructorEntityType::WEBINAR, $assignment->entity_type );
		self::assertSame( 123, $assignment->entity_id );
		self::assertSame( 7, $assignment->user_id );
		self::assertSame( InstructorRole::CO_INSTRUCTOR, $assignment->role_in_course );
		self::assertSame( 3, $assignment->display_order );
		self::assertSame( '2026-04-23 10:00:00', $assignment->assigned_at );
		self::assertSame( 1, $assignment->assigned_by );
	}

	public function test_from_row_coerces_numeric_strings_to_int(): void {
		$assignment = CourseInstructor::from_row( self::sample_row() );

		self::assertIsInt( $assignment->id );
		self::assertIsInt( $assignment->entity_id );
		self::assertIsInt( $assignment->user_id );
		self::assertIsInt( $assignment->display_order );
		self::assertIsInt( $assignment->assigned_by );
	}

	public function test_from_row_rejects_unknown_entity_type(): void {
		$row                = self::sample_row();
		$row['entity_type'] = 'lesson';

		$this->expectException( \InvalidArgumentException::class );

		CourseInstructor::from_row( $row );
	}

	public function test_from_row_rejects_unknown_role(): void {
		$row                   = self::sample_row();
		$row['role_in_course'] = 'owner';

		$this->expectException( \InvalidArgumentException::class );

		CourseInstructor::from_row( $row );
	}
}
