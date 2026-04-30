<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Quiz\Access;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Enrollment\EnrollmentStatus;
use VL\LMS\Domain\Quiz\QuizAttempt;
use VL\LMS\Domain\Quiz\QuizAttemptStatus;
use VL\LMS\Quiz\Access\QuizAccessGate;
use VL\LMS\Quiz\QuizCourseResolver;
use VL\LMS\Repositories\QuizAttemptRepository;
use VL\LMS\Services\Enrollment\EnrollmentService;
use VL\LMS\Tests\Fixtures\InMemoryEnrollmentRepository;
use VL\LMS\Tests\Fixtures\InMemoryQuizAttemptRepository;
use WP_Post;

final class QuizAccessGateTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private InMemoryEnrollmentRepository $enrollment_repo;
	private EnrollmentService $enrollments;
	private InMemoryQuizAttemptRepository $attempts;

	/** @var array<int, array<string, mixed>> */
	private array $meta = [];

	private ?int $resolved_course_id = 50;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();

		$this->enrollment_repo = new InMemoryEnrollmentRepository();
		$this->enrollments     = new EnrollmentService( $this->enrollment_repo );
		$this->attempts        = new InMemoryQuizAttemptRepository();
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

	private function gate(): QuizAccessGate {
		$resolver_course_id = &$this->resolved_course_id;
		$resolver           = new class( $resolver_course_id ) extends QuizCourseResolver {

			/** @var int|null */
			private $course_id_ref;

			public function __construct( ?int &$course_id ) {
				$this->course_id_ref = &$course_id;
			}

			public function find_course_id_for_quiz( int $quiz_id ): ?int {
				return $this->course_id_ref;
			}
		};

		return new QuizAccessGate( $this->enrollments, $this->attempts, $resolver );
	}

	private function quiz_post( int $id, string $status = 'publish' ): WP_Post {
		$post              = Mockery::mock( 'WP_Post' );
		$post->ID          = $id;
		$post->post_type   = 'vl_quiz';
		$post->post_status = $status;
		assert( $post instanceof WP_Post );
		return $post;
	}

	private function attempt(
		int $id = 17,
		int $user_id = 5,
		int $course_id = 50
	): QuizAttempt {
		$now = new \DateTimeImmutable( '2026-04-29 10:00:00', new \DateTimeZone( 'UTC' ) );
		return new QuizAttempt(
			$id,
			$user_id,
			101,
			$course_id,
			QuizAttemptStatus::IN_PROGRESS,
			$now,
			null,
			0,
			null,
			null,
			100,
			null,
			0,
			[],
			$now,
			$now
		);
	}

	private function set_meta( int $post_id, string $key, mixed $value ): void {
		$this->meta[ $post_id ][ $key ] = $value;
	}

	public function test_start_denies_when_quiz_unpublished(): void {
		$decision = $this->gate()->evaluate_for_start( 5, 101, $this->quiz_post( 101, 'draft' ) );

		self::assertFalse( $decision->allowed );
		self::assertSame( 'quiz_not_published', $decision->reason );
	}

	public function test_start_denies_when_resolver_returns_null(): void {
		$this->resolved_course_id = null;

		$decision = $this->gate()->evaluate_for_start( 5, 101, $this->quiz_post( 101 ) );

		self::assertFalse( $decision->allowed );
		self::assertSame( 'quiz_not_in_course', $decision->reason );
	}

	public function test_start_denies_when_not_enrolled(): void {
		// No enrollment seeded → has_active_access returns false.
		$decision = $this->gate()->evaluate_for_start( 5, 101, $this->quiz_post( 101 ) );

		self::assertFalse( $decision->allowed );
		self::assertSame( 'not_enrolled', $decision->reason );
		self::assertSame( 50, $decision->course_id );
	}

	public function test_start_denies_when_max_attempts_reached(): void {
		$this->seed_active_enrollment( 5, 50 );
		$this->set_meta( 101, '_vl_quiz_max_attempts', '2' );
		$this->seed_attempts( 5, 101, 50, 2 );

		$decision = $this->gate()->evaluate_for_start( 5, 101, $this->quiz_post( 101 ) );

		self::assertFalse( $decision->allowed );
		self::assertSame( 'attempts_exhausted', $decision->reason );
	}

	public function test_start_allows_when_under_max_attempts(): void {
		$this->seed_active_enrollment( 5, 50 );
		$this->set_meta( 101, '_vl_quiz_max_attempts', '3' );
		$this->seed_attempts( 5, 101, 50, 1 );

		$decision = $this->gate()->evaluate_for_start( 5, 101, $this->quiz_post( 101 ) );

		self::assertTrue( $decision->allowed );
		self::assertSame( 50, $decision->course_id );
	}

	public function test_start_treats_zero_max_attempts_as_unlimited(): void {
		$this->seed_active_enrollment( 5, 50 );
		$this->set_meta( 101, '_vl_quiz_max_attempts', '0' );
		// Seed many attempts — gate must not consult the count.
		$this->seed_attempts( 5, 101, 50, 99 );

		$decision = $this->gate()->evaluate_for_start( 5, 101, $this->quiz_post( 101 ) );

		self::assertTrue( $decision->allowed );
	}

	public function test_attempt_action_denies_when_user_mismatch(): void {
		$decision = $this->gate()->evaluate_for_attempt_action( 99, $this->attempt() );

		self::assertFalse( $decision->allowed );
		self::assertSame( 'forbidden', $decision->reason );
	}

	public function test_attempt_action_denies_when_not_enrolled(): void {
		$decision = $this->gate()->evaluate_for_attempt_action( 5, $this->attempt() );

		self::assertFalse( $decision->allowed );
		self::assertSame( 'not_enrolled', $decision->reason );
	}

	public function test_attempt_action_allows_when_owner_and_enrolled(): void {
		$this->seed_active_enrollment( 5, 50 );

		$decision = $this->gate()->evaluate_for_attempt_action( 5, $this->attempt() );

		self::assertTrue( $decision->allowed );
		self::assertSame( 50, $decision->course_id );
	}

	private function seed_active_enrollment( int $user_id, int $course_id ): void {
		$this->enrollment_repo->seed(
			[
				'user_id'   => $user_id,
				'course_id' => $course_id,
				'status'    => EnrollmentStatus::ACTIVE->value,
			]
		);
	}

	private function seed_attempts( int $user_id, int $quiz_id, int $course_id, int $count ): void {
		$now = new \DateTimeImmutable( '2026-04-29 10:00:00', new \DateTimeZone( 'UTC' ) );
		for ( $i = 0; $i < $count; $i++ ) {
			$this->attempts->insert(
				new QuizAttempt(
					0,
					$user_id,
					$quiz_id,
					$course_id,
					QuizAttemptStatus::SUBMITTED,
					$now,
					$now,
					0,
					null,
					0,
					100,
					false,
					70,
					[],
					$now,
					$now
				)
			);
		}
	}
}
