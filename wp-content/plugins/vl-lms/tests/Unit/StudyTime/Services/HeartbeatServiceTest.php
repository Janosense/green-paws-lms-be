<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\StudyTime\Services;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Learn\EntityHierarchy;
use VL\LMS\Services\Enrollment\EnrollmentService;
use VL\LMS\StudyTime\Domain\StudyKind;
use VL\LMS\StudyTime\Services\Exception\HeartbeatFailedException;
use VL\LMS\StudyTime\Services\HeartbeatService;
use VL\LMS\StudyTime\StudyTimeConfig;
use VL\LMS\Tests\Fixtures\StudyTime\InMemoryStudyTimeRepository;
use WP_Post;

final class HeartbeatServiceTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const USER     = 7;
	private const COURSE_A = 42;
	private const COURSE_B = 43;
	private const LESSON   = 101;
	private const TOPIC    = 205;

	/** @var Mockery\MockInterface&EntityHierarchy */
	private $hierarchy;

	/** @var Mockery\MockInterface&EnrollmentService */
	private $enrollments;

	private InMemoryStudyTimeRepository $ledger;

	/** @var array<int, WP_Post> */
	private array $posts = [];

	/** @var array<int, int> */
	private array $courses = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'get_post' )->alias(
			fn ( int $id ): ?WP_Post => $this->posts[ $id ] ?? null
		);

		$this->hierarchy   = Mockery::mock( EntityHierarchy::class );
		$this->enrollments = Mockery::mock( EnrollmentService::class );
		$this->ledger      = new InMemoryStudyTimeRepository();

		$this->hierarchy->shouldReceive( 'resolveCourse' )->andReturnUsing(
			function ( WP_Post $post ): ?WP_Post {
				$course_id = $this->courses[ (int) $post->ID ] ?? null;
				return null === $course_id ? null : $this->course_post( $course_id );
			}
		)->byDefault();
		$this->enrollments->shouldReceive( 'has_active_access' )->andReturn( true )->byDefault();

		$this->stage_entity( self::LESSON, 'vl_lesson', self::COURSE_A );
		$this->stage_entity( self::TOPIC, 'vl_topic', self::COURSE_A );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function service( int $cap_seconds = 45 ): HeartbeatService {
		return new HeartbeatService(
			$this->hierarchy,
			$this->enrollments,
			$this->ledger,
			new StudyTimeConfig( 120, 30, $cap_seconds )
		);
	}

	private function stage_entity( int $id, string $post_type, ?int $course_id, string $status = 'publish' ): void {
		$post              = Mockery::mock( 'WP_Post' );
		$post->ID          = $id;
		$post->post_type   = $post_type;
		$post->post_status = $status;
		assert( $post instanceof WP_Post );
		$this->posts[ $id ] = $post;

		if ( null !== $course_id ) {
			$this->courses[ $id ] = $course_id;
		}
	}

	private function course_post( int $id ): WP_Post {
		$post            = Mockery::mock( 'WP_Post' );
		$post->ID        = $id;
		$post->post_type = 'vl_course';
		assert( $post instanceof WP_Post );
		return $post;
	}

	private static function at( string $time ): \DateTimeImmutable {
		return new \DateTimeImmutable( $time, new \DateTimeZone( 'UTC' ) );
	}

	public function test_a_first_signal_in_a_course_adds_nothing_and_only_stamps_the_row(): void {
		$result = $this->service()->record( self::USER, 'lesson', self::LESSON, StudyKind::VIDEO, self::at( '2026-09-16 10:00:00' ) );

		self::assertSame( 0, $result->active_seconds );
		self::assertSame( 0, $result->course_active_seconds );
		self::assertSame( 1, $this->ledger->row_count() );
	}

	public function test_a_second_signal_adds_the_elapsed_seconds(): void {
		$service = $this->service();
		$service->record( self::USER, 'lesson', self::LESSON, StudyKind::VIDEO, self::at( '2026-09-16 10:00:00' ) );

		$result = $service->record( self::USER, 'lesson', self::LESSON, StudyKind::VIDEO, self::at( '2026-09-16 10:00:30' ) );

		self::assertSame( 30, $result->active_seconds );
		self::assertSame( 30, $result->course_active_seconds );
	}

	public function test_a_long_gap_adds_the_cap_not_the_elapsed_time(): void {
		// The learner left the tab open: ten minutes passed, but a signal may
		// never add more than the cap.
		$service = $this->service();
		$service->record( self::USER, 'lesson', self::LESSON, StudyKind::VIDEO, self::at( '2026-09-16 10:00:00' ) );

		$result = $service->record( self::USER, 'lesson', self::LESSON, StudyKind::VIDEO, self::at( '2026-09-16 10:10:00' ) );

		self::assertSame( 45, $result->active_seconds );
	}

	public function test_the_cap_comes_from_the_configuration(): void {
		$service = $this->service( 10 );
		$service->record( self::USER, 'lesson', self::LESSON, StudyKind::VIDEO, self::at( '2026-09-16 10:00:00' ) );

		$result = $service->record( self::USER, 'lesson', self::LESSON, StudyKind::VIDEO, self::at( '2026-09-16 10:10:00' ) );

		self::assertSame( 10, $result->active_seconds );
	}

	public function test_two_entities_of_one_course_never_exceed_wall_clock_time(): void {
		// Two tabs of the same course, signalling 15 s apart. Each signal is
		// measured from the learner's last signal anywhere in the course, so
		// the minute goes to whichever tab reported it.
		$service = $this->service();
		$service->record( self::USER, 'lesson', self::LESSON, StudyKind::VIDEO, self::at( '2026-09-16 10:00:00' ) );

		$topic  = $service->record( self::USER, 'topic', self::TOPIC, StudyKind::READING, self::at( '2026-09-16 10:00:15' ) );
		$lesson = $service->record( self::USER, 'lesson', self::LESSON, StudyKind::VIDEO, self::at( '2026-09-16 10:00:30' ) );

		self::assertSame( 15, $topic->active_seconds );
		self::assertSame( 15, $lesson->active_seconds );
		// 30 s of wall clock since the first signal, 30 s in the ledger.
		self::assertSame( 30, $lesson->course_active_seconds );
	}

	public function test_a_signal_in_another_course_does_not_move_this_courses_base(): void {
		$this->stage_entity( 301, 'vl_lesson', self::COURSE_B );
		$service = $this->service();
		$service->record( self::USER, 'lesson', self::LESSON, StudyKind::VIDEO, self::at( '2026-09-16 10:00:00' ) );

		// A beat in course B at 10:00:50 …
		$service->record( self::USER, 'lesson', 301, StudyKind::VIDEO, self::at( '2026-09-16 10:00:50' ) );
		// … and course A still measures from its own last signal, 10:00:00.
		$result = $service->record( self::USER, 'lesson', self::LESSON, StudyKind::VIDEO, self::at( '2026-09-16 10:00:20' ) );

		self::assertSame( 20, $result->active_seconds );
		self::assertSame( 20, $result->course_active_seconds );
	}

	public function test_video_and_reading_on_one_entity_keep_separate_rows(): void {
		$service = $this->service();
		$service->record( self::USER, 'lesson', self::LESSON, StudyKind::VIDEO, self::at( '2026-09-16 10:00:00' ) );
		$service->record( self::USER, 'lesson', self::LESSON, StudyKind::VIDEO, self::at( '2026-09-16 10:00:30' ) );

		$reading = $service->record( self::USER, 'lesson', self::LESSON, StudyKind::READING, self::at( '2026-09-16 10:01:00' ) );

		self::assertSame( 2, $this->ledger->row_count() );
		self::assertSame( 30, $reading->active_seconds );
		self::assertSame( 60, $reading->course_active_seconds );
	}

	public function test_a_stored_signal_in_the_future_adds_zero_never_a_negative(): void {
		// Two PHP workers whose clocks differ by a second can store a signal
		// slightly ahead of the next request's "now".
		$this->ledger->seed( self::USER, self::COURSE_A, 'lesson', self::LESSON, StudyKind::VIDEO, 30, self::at( '2026-09-16 10:05:00' ) );

		$result = $this->service()->record( self::USER, 'lesson', self::LESSON, StudyKind::VIDEO, self::at( '2026-09-16 10:04:58' ) );

		self::assertSame( 30, $result->active_seconds );
	}

	public function test_an_unknown_entity_is_refused_and_writes_nothing(): void {
		$code = $this->refusal_code( fn (): mixed => $this->service()->record( self::USER, 'lesson', 999, StudyKind::VIDEO, self::at( '2026-09-16 10:00:00' ) ) );

		self::assertSame( HeartbeatFailedException::ENTITY_NOT_FOUND, $code );
		self::assertSame( 0, $this->ledger->row_count() );
	}

	public function test_an_unpublished_entity_is_refused(): void {
		$this->stage_entity( 400, 'vl_lesson', self::COURSE_A, 'draft' );

		$code = $this->refusal_code( fn (): mixed => $this->service()->record( self::USER, 'lesson', 400, StudyKind::VIDEO, self::at( '2026-09-16 10:00:00' ) ) );

		self::assertSame( HeartbeatFailedException::ENTITY_NOT_FOUND, $code );
		self::assertSame( 0, $this->ledger->row_count() );
	}

	public function test_an_entity_of_the_wrong_type_is_refused(): void {
		// The body says `lesson`, the post is a topic.
		$code = $this->refusal_code( fn (): mixed => $this->service()->record( self::USER, 'lesson', self::TOPIC, StudyKind::VIDEO, self::at( '2026-09-16 10:00:00' ) ) );

		self::assertSame( HeartbeatFailedException::ENTITY_NOT_FOUND, $code );
		self::assertSame( 0, $this->ledger->row_count() );
	}

	public function test_an_entity_whose_course_cannot_be_resolved_is_refused(): void {
		$this->stage_entity( 500, 'vl_lesson', null );

		$code = $this->refusal_code( fn (): mixed => $this->service()->record( self::USER, 'lesson', 500, StudyKind::VIDEO, self::at( '2026-09-16 10:00:00' ) ) );

		self::assertSame( HeartbeatFailedException::ENTITY_NOT_FOUND, $code );
		self::assertSame( 0, $this->ledger->row_count() );
	}

	public function test_a_learner_without_active_access_is_refused(): void {
		$this->enrollments->shouldReceive( 'has_active_access' )->with( self::USER, self::COURSE_A )->andReturn( false );

		$code = $this->refusal_code( fn (): mixed => $this->service()->record( self::USER, 'lesson', self::LESSON, StudyKind::VIDEO, self::at( '2026-09-16 10:00:00' ) ) );

		self::assertSame( HeartbeatFailedException::NOT_ENROLLED, $code );
		self::assertSame( 0, $this->ledger->row_count() );
	}

	/**
	 * Runs a heartbeat that must be refused and returns its reason code.
	 */
	private function refusal_code( callable $call ): string {
		try {
			$call();
		} catch ( HeartbeatFailedException $e ) {
			return $e->error_code;
		}
		self::fail( 'The heartbeat was accepted, but it should have been refused.' );
	}
}
