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
use VL\LMS\Learn\Progression\CurriculumStop;
use VL\LMS\Learn\Progression\LockState;
use VL\LMS\Learn\Progression\QuizRef;
use VL\LMS\Services\Enrollment\EnrollmentService;
use VL\LMS\Tests\Fixtures\InMemoryEnrollmentRepository;
use VL\LMS\Tests\Fixtures\StubProgressionGate;
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

		$gate = new LessonAccessGate( $this->enrollments, $hierarchy, new StubProgressionGate() );

		$decision = $gate->check( 1, $lesson );

		self::assertFalse( $decision->allowed );
		self::assertSame( 'parent_not_found', $decision->reason );
		self::assertSame( 0, $decision->course_id );
	}

	public function test_denies_when_course_unpublished(): void {
		$lesson = $this->makePost( 10, 'vl_lesson' );
		$course = $this->makePost( 1, 'vl_course', 'draft' );

		$hierarchy = $this->makeHierarchy( [ 10 => $course ] );

		$gate = new LessonAccessGate( $this->enrollments, $hierarchy, new StubProgressionGate() );

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

		$gate = new LessonAccessGate( $this->enrollments, $hierarchy, new StubProgressionGate() );

		$decision = $gate->check( 99, $lesson );

		self::assertTrue( $decision->allowed );
		self::assertTrue( $decision->is_preview );
		self::assertSame( 1, $decision->course_id );
	}

	public function test_denies_not_enrolled_when_no_preview_and_no_enrollment(): void {
		$lesson = $this->makePost( 10, 'vl_lesson' );
		$course = $this->makePost( 1, 'vl_course' );

		$hierarchy = $this->makeHierarchy( [ 10 => $course ] );

		$gate = new LessonAccessGate( $this->enrollments, $hierarchy, new StubProgressionGate() );

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
		$gate = new LessonAccessGate( $this->enrollments, $hierarchy, new StubProgressionGate() );

		$decision = $gate->check( 5, $lesson );

		self::assertTrue( $decision->allowed );
		self::assertFalse( $decision->is_preview );
		self::assertSame( 1, $decision->course_id );
	}

	/**
	 * `_vl_lesson_requires_completion` is registered, admin-editable, and
	 * read by nothing — and it defaults to `true` on every lesson. The
	 * previous-sibling prerequisite gate that once consumed it was removed
	 * in `e3ce4ef`, and the progression gate added later is driven by
	 * per-quiz flags instead. If this test ever starts failing, something
	 * has begun reading that meta again, which would lock every course in
	 * production on deploy.
	 */
	public function test_requires_completion_meta_still_gates_nothing(): void {
		$prior  = $this->makePost( 9, 'vl_lesson' );
		$lesson = $this->makePost( 10, 'vl_lesson' );
		$course = $this->makePost( 1, 'vl_course' );

		$this->setMeta( 9, '_vl_lesson_requires_completion', '1' );

		$hierarchy = $this->makeHierarchy( [ 10 => $course ], [ 10 => $prior ] );

		$this->seedActiveEnrollment( 5, 1 );
		$gate = new LessonAccessGate( $this->enrollments, $hierarchy, new StubProgressionGate() );

		$decision = $gate->check( 5, $lesson );

		self::assertTrue( $decision->allowed );
		self::assertSame( 1, $decision->course_id );
	}

	public function test_denies_locked_lesson_and_carries_the_blocking_quiz(): void {
		$lesson = $this->makePost( 10, 'vl_lesson' );
		$course = $this->makePost( 1, 'vl_course' );

		$lock        = LockState::progression( new QuizRef( 77, 'module-1-test', 'Тест до модуля 1' ) );
		$progression = new StubProgressionGate( $lock );

		$hierarchy = $this->makeHierarchy( [ 10 => $course ] );
		$this->seedActiveEnrollment( 5, 1 );

		$decision = ( new LessonAccessGate( $this->enrollments, $hierarchy, $progression ) )->check( 5, $lesson );

		self::assertFalse( $decision->allowed );
		self::assertSame( 'progression_locked', $decision->reason );
		self::assertSame( 1, $decision->course_id );
		self::assertSame( $lock, $decision->lock );
		self::assertSame( 77, $decision->lock?->blocking_quiz?->id );
	}

	public function test_passes_topic_kind_to_the_progression_gate(): void {
		$topic  = $this->makePost( 20, 'vl_topic' );
		$course = $this->makePost( 1, 'vl_course' );

		$progression = new StubProgressionGate();
		$hierarchy   = $this->makeHierarchy( [ 20 => $course ] );
		$this->seedActiveEnrollment( 5, 1 );

		( new LessonAccessGate( $this->enrollments, $hierarchy, $progression ) )->check( 5, $topic );

		self::assertSame(
			[
				[
					'user_id'   => 5,
					'course_id' => 1,
					'kind'      => CurriculumStop::KIND_TOPIC,
					'entity_id' => 20,
				],
			],
			$progression->calls
		);
	}

	/**
	 * A free preview lesson returns at step 3, so the progression gate is
	 * never consulted — that is what keeps previewing free of queries.
	 */
	public function test_preview_lesson_short_circuits_before_the_progression_check(): void {
		$lesson = $this->makePost( 10, 'vl_lesson' );
		$course = $this->makePost( 1, 'vl_course' );

		$this->setMeta( 10, '_vl_lesson_is_preview', '1' );

		$progression = new StubProgressionGate(
			LockState::progression( new QuizRef( 77, 'blocker', 'Blocker' ) )
		);
		$hierarchy = $this->makeHierarchy( [ 10 => $course ] );

		$decision = ( new LessonAccessGate( $this->enrollments, $hierarchy, $progression ) )->check( 5, $lesson );

		self::assertTrue( $decision->allowed );
		self::assertTrue( $decision->is_preview );
		self::assertFalse( $progression->was_called() );
	}

	/**
	 * An unenrolled learner gets `not_enrolled`, not a lock verdict —
	 * being told to go pass a quiz you could not sit anyway is useless.
	 */
	public function test_enrollment_denial_outranks_a_progression_lock(): void {
		$lesson = $this->makePost( 10, 'vl_lesson' );
		$course = $this->makePost( 1, 'vl_course' );

		$progression = new StubProgressionGate(
			LockState::progression( new QuizRef( 77, 'blocker', 'Blocker' ) )
		);
		$hierarchy = $this->makeHierarchy( [ 10 => $course ] );

		$decision = ( new LessonAccessGate( $this->enrollments, $hierarchy, $progression ) )->check( 99, $lesson );

		self::assertFalse( $decision->allowed );
		self::assertSame( 'not_enrolled', $decision->reason );
		self::assertFalse( $progression->was_called() );
	}

	public function test_topic_does_not_use_preview_bypass(): void {
		$topic  = $this->makePost( 20, 'vl_topic' );
		$course = $this->makePost( 1, 'vl_course' );

		// Even with the preview meta set on the topic, only `vl_lesson`
		// posts unlock the preview path — topics fall through to enrollment.
		$this->setMeta( 20, '_vl_lesson_is_preview', '1' );

		$hierarchy = $this->makeHierarchy( [ 20 => $course ] );

		$gate = new LessonAccessGate( $this->enrollments, $hierarchy, new StubProgressionGate() );

		$decision = $gate->check( 99, $topic );

		self::assertFalse( $decision->allowed );
		self::assertSame( 'not_enrolled', $decision->reason );
	}
}
