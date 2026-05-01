<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Certificate;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Certificate\SnapshotBuilder;
use VL\LMS\Domain\CourseInstructor\CourseInstructor;
use VL\LMS\Domain\CourseInstructor\InstructorEntityType;
use VL\LMS\Domain\CourseInstructor\InstructorRole;
use VL\LMS\Services\CourseInstructors\CourseInstructorService;
use VL\LMS\Tests\Fixtures\InMemoryCourseInstructorRepository;

final class SnapshotBuilderTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private InMemoryCourseInstructorRepository $instructor_repo;

	private CourseInstructorService $instructors;

	private SnapshotBuilder $builder;

	/** @var array<int, object> */
	private array $users = [];

	/** @var array<int, object> */
	private array $posts = [];

	private string $issuer_option = 'Green Paws LMS';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->users         = [];
		$this->posts         = [];
		$this->issuer_option = 'Green Paws LMS';

		$users = &$this->users;
		Functions\when( 'get_user_by' )->alias(
			static function ( string $by, mixed $value ) use ( &$users ): mixed {
				if ( 'id' !== $by ) {
					return false;
				}
				return $users[ (int) $value ] ?? false;
			}
		);

		$posts = &$this->posts;
		Functions\when( 'get_post' )->alias(
			static function ( int $id ) use ( &$posts ): mixed {
				return $posts[ $id ] ?? null;
			}
		);

		$option_ref = &$this->issuer_option;
		Functions\when( 'get_option' )->alias(
			static function ( string $key, mixed $default = false ) use ( &$option_ref ): mixed {
				if ( 'vl_lms_certificate_issuer' === $key ) {
					return $option_ref;
				}
				return $default;
			}
		);

		$this->instructor_repo = new InMemoryCourseInstructorRepository();
		$this->instructors     = new CourseInstructorService( $this->instructor_repo );
		$this->builder         = new SnapshotBuilder( $this->instructors );
	}

	private function seed_instructors_for_course( int $course_id, int ...$user_ids ): void {
		foreach ( $user_ids as $idx => $uid ) {
			$this->instructor_repo->insert(
				[
					'entity_type'    => InstructorEntityType::COURSE->value,
					'entity_id'      => $course_id,
					'user_id'        => $uid,
					'role_in_course' => InstructorRole::CO_INSTRUCTOR->value,
					'display_order'  => $idx,
					'assigned_at'    => '2026-04-29 10:00:00',
					'assigned_by'    => 1,
				]
			);
		}
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function user(
		int $id,
		string $first = '',
		string $last = '',
		string $display = '',
		string $login = ''
	): object {
		$user               = Mockery::mock( 'WP_User' );
		$user->ID           = $id;
		$user->first_name   = $first;
		$user->last_name    = $last;
		$user->display_name = $display;
		$user->user_login   = $login;
		$this->users[ $id ] = $user;
		return $user;
	}

	private function post( int $id, string $title, string $slug, int $author = 0 ): object {
		$post               = Mockery::mock( 'WP_Post' );
		$post->ID           = $id;
		$post->post_title   = $title;
		$post->post_name    = $slug;
		$post->post_author  = $author;
		$this->posts[ $id ] = $post;
		return $post;
	}

	public function test_happy_path_with_first_last_names_and_two_instructors(): void {
		$this->user( 5, 'Богдан', 'Коваль', 'Bohdan K.', 'student.bohdan' );
		$this->user( 7, '', '', 'Олена Шевченко', 'instructor.olena' );
		$this->user( 8, '', '', 'Ілля Орел', 'instructor.illya' );
		$this->post( 50, 'Анестезіологія для практиків', 'anaesthesiology-for-practitioners', 7 );

		$this->seed_instructors_for_course( 50, 7, 8 );

		$snapshot = $this->builder->build_snapshot(
			5,
			50,
			92,
			100,
			new \DateTimeImmutable( '2026-04-29T10:00:00+00:00' )
		);

		self::assertSame( 'Анестезіологія для практиків', $snapshot['course_title'] );
		self::assertSame( 'anaesthesiology-for-practitioners', $snapshot['course_slug'] );
		self::assertSame( 'Богдан Коваль', $snapshot['learner_full_name'] );
		self::assertSame( 'Богдан К.', $snapshot['learner_display_name'] );
		self::assertSame( [ 'Олена Шевченко', 'Ілля Орел' ], $snapshot['instructor_names'] );
		self::assertSame( 'Green Paws LMS', $snapshot['issuer_name'] );
		self::assertSame( '2026-04-29T10:00:00+00:00', $snapshot['issued_at_iso'] );
		self::assertSame( 92, $snapshot['final_score_pct'] );
		self::assertSame( 'v1', $snapshot['template_version'] );
	}

	public function test_falls_back_to_display_name_when_first_last_empty(): void {
		$this->user( 5, '', '', 'Богдан К.', 'student.bohdan' );
		$this->post( 50, 'Course', 'course' );
		// (no instructors seeded — list returns [])

		$snapshot = $this->builder->build_snapshot(
			5,
			50,
			null,
			null,
			new \DateTimeImmutable( '2026-04-29T10:00:00+00:00' )
		);

		self::assertSame( 'Богдан К.', $snapshot['learner_full_name'] );
		self::assertSame( 'Богдан К.', $snapshot['learner_display_name'] );
	}

	public function test_falls_back_to_user_login_when_no_names_available(): void {
		$this->user( 5, '', '', '', 'student.bohdan' );
		$this->post( 50, 'Course', 'course' );
		// (no instructors seeded — list returns [])

		$snapshot = $this->builder->build_snapshot(
			5,
			50,
			null,
			null,
			new \DateTimeImmutable( '2026-04-29T10:00:00+00:00' )
		);

		self::assertSame( 'student.bohdan', $snapshot['learner_full_name'] );
		self::assertSame( 'student.bohdan', $snapshot['learner_display_name'] );
	}

	public function test_falls_back_to_post_author_when_instructor_list_empty(): void {
		$this->user( 5, 'Богдан', 'Коваль' );
		$this->user( 9, '', '', 'Author Name' );
		$this->post( 50, 'Course', 'course', 9 );
		// (no instructors seeded — list returns [])

		$snapshot = $this->builder->build_snapshot(
			5,
			50,
			null,
			null,
			new \DateTimeImmutable( '2026-04-29T10:00:00+00:00' )
		);

		self::assertSame( [ 'Author Name' ], $snapshot['instructor_names'] );
	}

	public function test_no_final_exam_yields_null_pct(): void {
		$this->user( 5, 'Богдан', 'Коваль' );
		$this->post( 50, 'Course', 'course' );
		// (no instructors seeded — list returns [])

		$snapshot = $this->builder->build_snapshot(
			5,
			50,
			null,
			null,
			new \DateTimeImmutable( '2026-04-29T10:00:00+00:00' )
		);

		self::assertNull( $snapshot['final_score_pct'] );
	}

	public function test_zero_max_score_yields_null_pct(): void {
		$this->user( 5, 'Богдан', 'Коваль' );
		$this->post( 50, 'Course', 'course' );
		// (no instructors seeded — list returns [])

		$snapshot = $this->builder->build_snapshot(
			5,
			50,
			0,
			0,
			new \DateTimeImmutable( '2026-04-29T10:00:00+00:00' )
		);

		self::assertNull( $snapshot['final_score_pct'] );
	}

	public function test_score_pct_rounds_half_up(): void {
		$this->user( 5, 'Богдан', 'Коваль' );
		$this->post( 50, 'Course', 'course' );
		// (no instructors seeded — list returns [])

		$snapshot = $this->builder->build_snapshot(
			5,
			50,
			5,
			8,
			new \DateTimeImmutable( '2026-04-29T10:00:00+00:00' )
		);

		// 5/8 = 62.5 → 63
		self::assertSame( 63, $snapshot['final_score_pct'] );
	}

	public function test_issuer_falls_back_to_default_when_option_empty(): void {
		$this->issuer_option = '';
		$this->user( 5, 'Богдан', 'Коваль' );
		$this->post( 50, 'Course', 'course' );
		// (no instructors seeded — list returns [])

		$snapshot = $this->builder->build_snapshot(
			5,
			50,
			null,
			null,
			new \DateTimeImmutable( '2026-04-29T10:00:00+00:00' )
		);

		self::assertSame( 'Green Paws LMS', $snapshot['issuer_name'] );
	}

	public function test_custom_issuer_option_passes_through(): void {
		$this->issuer_option = 'Acme Veterinary Academy';
		$this->user( 5, 'Богдан', 'Коваль' );
		$this->post( 50, 'Course', 'course' );
		// (no instructors seeded — list returns [])

		$snapshot = $this->builder->build_snapshot(
			5,
			50,
			null,
			null,
			new \DateTimeImmutable( '2026-04-29T10:00:00+00:00' )
		);

		self::assertSame( 'Acme Veterinary Academy', $snapshot['issuer_name'] );
	}

	public function test_missing_user_yields_empty_strings(): void {
		// No user 999 seeded.
		$this->post( 50, 'Course', 'course' );
		// (no instructors seeded — list returns [])

		$snapshot = $this->builder->build_snapshot(
			999,
			50,
			null,
			null,
			new \DateTimeImmutable( '2026-04-29T10:00:00+00:00' )
		);

		self::assertSame( '', $snapshot['learner_full_name'] );
		self::assertSame( '', $snapshot['learner_display_name'] );
	}
}
