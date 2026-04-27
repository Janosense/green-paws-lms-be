<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Catalog\Detail;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Catalog\Detail\InstructorListTransformer;
use VL\LMS\Domain\CourseInstructor\CourseInstructor;
use VL\LMS\Domain\CourseInstructor\InstructorEntityType;
use VL\LMS\Domain\CourseInstructor\InstructorRole;
use WP_User;

final class InstructorListTransformerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private InstructorListTransformer $transformer;

	/** @var array<int, WP_User> */
	private array $users = [];

	/** @var array<string, array<int, mixed>> */
	private array $user_meta = [];

	/** @var array<int, array<string, mixed>> */
	private array $attachments = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'get_user_by' )->alias(
			fn ( string $field, int $id ): WP_User|false => $this->users[ $id ] ?? false
		);
		Functions\when( 'get_user_meta' )->alias(
			fn ( int $user_id, string $key, bool $single ): mixed => $this->user_meta[ $key ][ $user_id ] ?? ''
		);
		Functions\when( 'wp_get_attachment_image_src' )->alias(
			fn ( int $id, string $size ): array|false => $this->attachments[ $id ] ?? false
		);
		Functions\when( 'get_avatar_url' )->alias(
			static fn ( int $user_id, array $args ): string => "https://gravatar.test/{$user_id}?s=" . (int) ( $args['size'] ?? 96 )
		);

		$this->transformer = new InstructorListTransformer();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_returns_empty_for_no_assignments(): void {
		self::assertSame( [], $this->transformer->transform( [] ) );
	}

	public function test_passes_role_in_course_through(): void {
		$this->users[5] = $this->user( 5, 'Lead' );
		$this->users[8] = $this->user( 8, 'Co' );

		$result = $this->transformer->transform(
			[
				$this->assignment( 1, 5, 100, InstructorRole::LEAD, 0 ),
				$this->assignment( 2, 8, 100, InstructorRole::CO_INSTRUCTOR, 1 ),
			]
		);

		self::assertCount( 2, $result );
		self::assertSame( 'lead', $result[0]['role_in_course'] );
		self::assertSame( 'co_instructor', $result[1]['role_in_course'] );
	}

	public function test_preserves_input_order_from_repository(): void {
		// The repository ORDER BYs at SELECT time. The transformer must not
		// re-sort: a list passed in display_order ASC, id ASC stays that way.
		$this->users[5] = $this->user( 5, 'A' );
		$this->users[8] = $this->user( 8, 'B' );
		$this->users[9] = $this->user( 9, 'C' );

		$result = $this->transformer->transform(
			[
				$this->assignment( 10, 5, 100, InstructorRole::LEAD, 0 ),
				$this->assignment( 11, 8, 100, InstructorRole::CO_INSTRUCTOR, 1 ),
				$this->assignment( 12, 9, 100, InstructorRole::CO_INSTRUCTOR, 2 ),
			]
		);

		self::assertSame( [ 5, 8, 9 ], array_column( $result, 'id' ) );
	}

	public function test_bio_html_is_passed_through_when_present(): void {
		$this->users[5]                          = $this->user( 5, 'Lead' );
		$this->user_meta['vl_instructor_bio'][5] = '<p>Board-certified <strong>cardiologist</strong>.</p>';

		$result = $this->transformer->transform(
			[ $this->assignment( 1, 5, 100, InstructorRole::LEAD, 0 ) ]
		);

		self::assertSame( '<p>Board-certified <strong>cardiologist</strong>.</p>', $result[0]['bio'] );
	}

	public function test_bio_is_empty_string_when_meta_unset(): void {
		$this->users[5] = $this->user( 5, 'Lead' );

		$result = $this->transformer->transform(
			[ $this->assignment( 1, 5, 100, InstructorRole::LEAD, 0 ) ]
		);

		self::assertSame( '', $result[0]['bio'] );
	}

	public function test_avatar_uses_attachment_when_meta_points_at_real_image(): void {
		$this->users[5]                                = $this->user( 5, 'Lead' );
		$this->user_meta['vl_instructor_avatar_id'][5] = 42;
		$this->attachments[42]                         = [ 'https://cdn.test/avatar.jpg', 96, 96, false ];

		$result = $this->transformer->transform(
			[ $this->assignment( 1, 5, 100, InstructorRole::LEAD, 0 ) ]
		);

		self::assertSame( 'https://cdn.test/avatar.jpg', $result[0]['avatar']['url'] );
		self::assertSame( 96, $result[0]['avatar']['size'] );
	}

	public function test_avatar_falls_back_to_gravatar_when_meta_zero(): void {
		$this->users[5] = $this->user( 5, 'Lead' );

		$result = $this->transformer->transform(
			[ $this->assignment( 1, 5, 100, InstructorRole::LEAD, 0 ) ]
		);

		self::assertSame( 'https://gravatar.test/5?s=96', $result[0]['avatar']['url'] );
	}

	public function test_skips_assignments_for_deleted_users(): void {
		// User 5 exists, user 99 does not (get_user_by returns false).
		$this->users[5] = $this->user( 5, 'Lead' );

		$result = $this->transformer->transform(
			[
				$this->assignment( 1, 5, 100, InstructorRole::LEAD, 0 ),
				$this->assignment( 2, 99, 100, InstructorRole::CO_INSTRUCTOR, 1 ),
			]
		);

		self::assertCount( 1, $result );
		self::assertSame( 5, $result[0]['id'] );
	}

	private function user( int $id, string $display_name ): WP_User {
		$user               = Mockery::mock( 'WP_User' );
		$user->ID           = $id;
		$user->display_name = $display_name;
		return $user;
	}

	private function assignment(
		int $id,
		int $user_id,
		int $entity_id,
		InstructorRole $role,
		int $display_order
	): CourseInstructor {
		return new CourseInstructor(
			id: $id,
			entity_type: InstructorEntityType::COURSE,
			entity_id: $entity_id,
			user_id: $user_id,
			role_in_course: $role,
			display_order: $display_order,
			assigned_at: '2026-01-01 00:00:00',
			assigned_by: $user_id,
		);
	}
}
