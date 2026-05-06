<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Learn\Access;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Enrollment\EnrollmentStatus;
use VL\LMS\Learn\Access\LessonAccessGate;
use VL\LMS\Learn\EntityHierarchy;
use VL\LMS\Services\Enrollment\EnrollmentService;
use VL\LMS\Tests\Fixtures\InMemoryEnrollmentRepository;
use WP_Post;

final class LessonAccessGateTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private InMemoryEnrollmentRepository $enrollment_repo;
	private EnrollmentService $enrollments;

	/** @var array<int, mixed> */
	private array $meta = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->enrollment_repo = new InMemoryEnrollmentRepository();
		$this->enrollments     = new EnrollmentService( $this->enrollment_repo );
		$this->meta            = [];

		$meta = &$this->meta;
		Functions\when( 'get_post_meta' )->alias(
			static function ( int $id, string $key, bool $single = false ) use ( &$meta ): mixed {
				return $meta[ $id ][ $key ] ?? '';
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function setMeta( int $post_id, string $key, mixed $value ): void {
		$this->meta[ $post_id ][ $key ] = $value;
	}

	private function makePost( int $id, string $type, string $status = 'publish' ): WP_Post {
		$post              = Mockery::mock( 'WP_Post' );
		$post->ID          = $id;
		$post->post_type   = $type;
		$post->post_status = $status;

		assert( $post instanceof WP_Post );
		return $post;
	}

	/**
	 * @param array<int, ?WP_Post> $course_for_post Map of post-ID → resolved course.
	 * @param array<int, ?WP_Post> $previous_for_post Map of post-ID → previous sibling.
	 */
	private function makeHierarchy( array $course_for_post, array $previous_for_post = [] ): EntityHierarchy {
		return new class( $course_for_post, $previous_for_post ) extends EntityHierarchy {

			/**
			 * @param array<int, ?WP_Post> $course_for_post
			 * @param array<int, ?WP_Post> $previous_for_post
			 */
			public function __construct(
				private array $course_for_post,
				private array $previous_for_post
			) {
			}

			public function resolveCourse( WP_Post $post ): ?WP_Post {
				return $this->course_for_post[ (int) $post->ID ] ?? null;
			}

			public function previousSibling( WP_Post $post ): ?WP_Post {
				return $this->previous_for_post[ (int) $post->ID ] ?? null;
			}
		};
	}

	private function seedActiveEnrollment( int $user_id, int $course_id ): void {
		$this->enrollment_repo->seed(
			[
				'user_id'   => $user_id,
				'course_id' => $course_id,
				'status'    => EnrollmentStatus::ACTIVE->value,
			]
		);
	}

	public function test_denies_when_course_cannot_be_resolved(): void {
		$lesson    = $this->makePost( 10, 'vl_lesson' );
		$hierarchy = $this->makeHierarchy( [ 10 => null ] );

		$gate = new LessonAccessGate( $this->enrollments, $hierarchy );

		$decision = $gate->check( 1, $lesson );

		self::assertFalse( $decision->allowed );
		self::assertSame( 'parent_not_found', $decision->reason );
		self::assertSame( 0, $decision->course_id );
	}

	public function test_denies_when_course_unpublished(): void {
		$lesson = $this->makePost( 10, 'vl_lesson' );
		$course = $this->makePost( 1, 'vl_course', 'draft' );

		$hierarchy = $this->makeHierarchy( [ 10 => $course ] );

		$gate = new LessonAccessGate( $this->enrollments, $hierarchy );

		$decision = $gate->check( 1, $lesson );

		self::assertFalse( $decision->allowed );
		self::assertSame( 'course_unpublished', $decision->reason );
		self::assertSame( 1, $decision->course_id );
	}

	public function test_preview_lesson_allows_non_enrolled_user(): void {
		$lesson = $this->makePost( 10, 'vl_lesson' );
		$course = $this->makePost( 1, 'vl_course' );

		$this->setMeta( 10, '_vl_lesson_is_preview', '1' );
		$hierarchy = $this->makeHierarchy( [ 10 => $course ] );

		$gate = new LessonAccessGate( $this->enrollments, $hierarchy );

		$decision = $gate->check( 99, $lesson );

		self::assertTrue( $decision->allowed );
		self::assertTrue( $decision->is_preview );
		self::assertSame( 1, $decision->course_id );
	}

	public function test_denies_not_enrolled_when_no_preview_and_no_enrollment(): void {
		$lesson = $this->makePost( 10, 'vl_lesson' );
		$course = $this->makePost( 1, 'vl_course' );

		$hierarchy = $this->makeHierarchy( [ 10 => $course ] );

		$gate = new LessonAccessGate( $this->enrollments, $hierarchy );

		$decision = $gate->check( 99, $lesson );

		self::assertFalse( $decision->allowed );
		self::assertSame( 'not_enrolled', $decision->reason );
		self::assertSame( 1, $decision->course_id );
	}

	public function test_allows_enrolled_lesson_with_no_previous_sibling(): void {
		$lesson = $this->makePost( 10, 'vl_lesson' );
		$course = $this->makePost( 1, 'vl_course' );

		$hierarchy = $this->makeHierarchy( [ 10 => $course ], [ 10 => null ] );

		$this->seedActiveEnrollment( 5, 1 );
		$gate = new LessonAccessGate( $this->enrollments, $hierarchy );

		$decision = $gate->check( 5, $lesson );

		self::assertTrue( $decision->allowed );
		self::assertFalse( $decision->is_preview );
		self::assertSame( 1, $decision->course_id );
	}

	public function test_allows_enrolled_lesson_regardless_of_previous_completion(): void {
		$prior  = $this->makePost( 9, 'vl_lesson' );
		$lesson = $this->makePost( 10, 'vl_lesson' );
		$course = $this->makePost( 1, 'vl_course' );

		// The previous lesson is flagged as `requires_completion` and the
		// learner has no progress row for it. Once the prerequisite gate
		// was lifted, enrollment alone is enough to view any lesson.
		$this->setMeta( 9, '_vl_lesson_requires_completion', '1' );

		$hierarchy = $this->makeHierarchy( [ 10 => $course ], [ 10 => $prior ] );

		$this->seedActiveEnrollment( 5, 1 );
		$gate = new LessonAccessGate( $this->enrollments, $hierarchy );

		$decision = $gate->check( 5, $lesson );

		self::assertTrue( $decision->allowed );
		self::assertSame( 1, $decision->course_id );
	}

	public function test_topic_does_not_use_preview_bypass(): void {
		$topic  = $this->makePost( 20, 'vl_topic' );
		$course = $this->makePost( 1, 'vl_course' );

		// Even with the preview meta set on the topic, only `vl_lesson`
		// posts unlock the preview path — topics fall through to enrollment.
		$this->setMeta( 20, '_vl_lesson_is_preview', '1' );

		$hierarchy = $this->makeHierarchy( [ 20 => $course ] );

		$gate = new LessonAccessGate( $this->enrollments, $hierarchy );

		$decision = $gate->check( 99, $topic );

		self::assertFalse( $decision->allowed );
		self::assertSame( 'not_enrolled', $decision->reason );
	}
}
