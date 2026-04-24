<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Services\CourseInstructors;

use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\CourseInstructor\InstructorEntityType;
use VL\LMS\Domain\CourseInstructor\InstructorRole;
use VL\LMS\Services\CourseInstructors\CourseInstructorService;
use VL\LMS\Tests\Fixtures\InMemoryCourseInstructorRepository;

final class CourseInstructorServiceTest extends TestCase {

	private InMemoryCourseInstructorRepository $repo;

	private CourseInstructorService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->repo    = new InMemoryCourseInstructorRepository();
		$this->service = new CourseInstructorService( $this->repo );
	}

	public function test_add_instructor_inserts_when_fresh(): void {
		$assignment = $this->service->add_instructor(
			InstructorEntityType::COURSE,
			123,
			7,
			InstructorRole::CO_INSTRUCTOR,
			1,
			2
		);

		self::assertSame( 7, $assignment->user_id );
		self::assertSame( 123, $assignment->entity_id );
		self::assertSame( InstructorRole::CO_INSTRUCTOR, $assignment->role_in_course );
		self::assertSame( 2, $assignment->display_order );
	}

	public function test_add_instructor_updates_when_already_assigned(): void {
		$first = $this->service->add_instructor(
			InstructorEntityType::COURSE,
			123,
			7,
			InstructorRole::ASSISTANT,
			1,
			0
		);

		$second = $this->service->add_instructor(
			InstructorEntityType::COURSE,
			123,
			7,
			InstructorRole::LEAD,
			1,
			5
		);

		self::assertSame( $first->id, $second->id );
		self::assertSame( InstructorRole::LEAD, $second->role_in_course );
		self::assertSame( 5, $second->display_order );
		self::assertCount( 1, $this->repo->list_for_entity( InstructorEntityType::COURSE, 123 ) );
	}

	public function test_remove_instructor_returns_pre_delete_object(): void {
		$assignment = $this->service->add_instructor(
			InstructorEntityType::COURSE,
			123,
			7,
			InstructorRole::CO_INSTRUCTOR,
			1
		);

		$removed = $this->service->remove_instructor( InstructorEntityType::COURSE, 123, 7 );

		self::assertNotNull( $removed );
		self::assertSame( $assignment->id, $removed->id );
		self::assertNull( $this->repo->find_by_id( $assignment->id ) );
	}

	public function test_remove_instructor_returns_null_when_missing(): void {
		self::assertNull( $this->service->remove_instructor( InstructorEntityType::COURSE, 123, 7 ) );
	}

	public function test_change_role_updates_existing_assignment(): void {
		$this->service->add_instructor(
			InstructorEntityType::COURSE,
			123,
			7,
			InstructorRole::CO_INSTRUCTOR,
			1
		);

		$updated = $this->service->change_role(
			InstructorEntityType::COURSE,
			123,
			7,
			InstructorRole::ASSISTANT
		);

		self::assertSame( InstructorRole::ASSISTANT, $updated->role_in_course );
	}

	public function test_change_role_throws_when_assignment_missing(): void {
		$this->expectException( \RuntimeException::class );

		$this->service->change_role(
			InstructorEntityType::COURSE,
			123,
			7,
			InstructorRole::ASSISTANT
		);
	}

	public function test_reorder_updates_display_order_for_listed_users(): void {
		$this->service->add_instructor( InstructorEntityType::COURSE, 123, 7, InstructorRole::LEAD, 1, 0 );
		$this->service->add_instructor( InstructorEntityType::COURSE, 123, 8, InstructorRole::CO_INSTRUCTOR, 1, 1 );

		$this->service->reorder(
			InstructorEntityType::COURSE,
			123,
			[
				7 => 10,
				8 => 20,
			]
		);

		$list    = $this->repo->list_for_entity( InstructorEntityType::COURSE, 123 );
		$by_user = [];
		foreach ( $list as $assignment ) {
			$by_user[ $assignment->user_id ] = $assignment->display_order;
		}
		self::assertSame( 10, $by_user[7] );
		self::assertSame( 20, $by_user[8] );
	}

	public function test_reorder_silently_skips_unknown_users(): void {
		$this->service->add_instructor( InstructorEntityType::COURSE, 123, 7, InstructorRole::LEAD, 1, 0 );

		$this->service->reorder(
			InstructorEntityType::COURSE,
			123,
			[
				7   => 5,
				999 => 99,
			]
		);

		$list = $this->repo->list_for_entity( InstructorEntityType::COURSE, 123 );
		self::assertCount( 1, $list );
		self::assertSame( 5, $list[0]->display_order );
	}

	public function test_list_for_entity_delegates_to_repo_with_ordering(): void {
		$this->service->add_instructor( InstructorEntityType::COURSE, 123, 8, InstructorRole::CO_INSTRUCTOR, 1, 5 );
		$this->service->add_instructor( InstructorEntityType::COURSE, 123, 7, InstructorRole::LEAD, 1, 0 );

		$list = $this->service->list_for_entity( InstructorEntityType::COURSE, 123 );

		self::assertCount( 2, $list );
		self::assertSame( 7, $list[0]->user_id, 'display_order = 0 should come first.' );
		self::assertSame( 8, $list[1]->user_id );
	}
}
