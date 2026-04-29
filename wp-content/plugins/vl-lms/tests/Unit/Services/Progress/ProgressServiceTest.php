<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Services\Progress;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Enrollment\EnrollmentStatus;
use VL\LMS\Domain\Progress\EntityType;
use VL\LMS\Domain\Progress\ProgressStatus;
use VL\LMS\Domain\Progress\ViewEventType;
use VL\LMS\Learn\EntityHierarchy;
use VL\LMS\Services\Progress\CompletionPropagator;
use VL\LMS\Services\Progress\PositionWriteRule;
use VL\LMS\Services\Progress\ProgressEventRequest;
use VL\LMS\Services\Progress\ProgressService;
use VL\LMS\Services\Progress\PropagationResult;
use VL\LMS\Tests\Fixtures\InMemoryEnrollmentRepository;
use VL\LMS\Tests\Fixtures\InMemoryLessonViewRepository;
use VL\LMS\Tests\Fixtures\InMemoryProgressRepository;
use WP_Post;

final class ProgressServiceTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const string VALID_UUID = '8c7e9f2a-2c1d-4d2c-9e89-3f5d2a3b4c5d';

	private InMemoryProgressRepository $progress;
	private InMemoryLessonViewRepository $views;
	private InMemoryEnrollmentRepository $enrollments;

	/** @var Mockery\MockInterface&EntityHierarchy */
	private $hierarchy;

	/** @var Mockery\MockInterface&CompletionPropagator */
	private $propagator;

	private \DateTimeImmutable $now;

	/** @var array<int, WP_Post> */
	private array $posts = [];

	/** @var array<string, array<int, mixed>> */
	private array $meta = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();

		$this->now         = new \DateTimeImmutable( '2026-04-28 12:00:00', new \DateTimeZone( 'UTC' ) );
		$this->progress    = new InMemoryProgressRepository( fn (): \DateTimeImmutable => $this->now );
		$this->views       = new InMemoryLessonViewRepository();
		$this->enrollments = new InMemoryEnrollmentRepository();
		$this->hierarchy   = Mockery::mock( EntityHierarchy::class );
		$this->propagator  = Mockery::mock( CompletionPropagator::class );

		$this->posts = [];
		$this->meta  = [];

		Functions\when( 'get_post' )->alias(
			fn ( int $id ): ?WP_Post => $this->posts[ $id ] ?? null
		);
		Functions\when( 'get_post_meta' )->alias(
			fn ( int $post_id, string $key ): mixed => $this->meta[ $key ][ $post_id ] ?? ''
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function service(): ProgressService {
		return new class(
			$this->progress,
			$this->views,
			$this->hierarchy,
			new PositionWriteRule(),
			$this->propagator,
			$this->enrollments,
			$this->now
		) extends ProgressService {

			public function __construct(
				InMemoryProgressRepository $progress,
				InMemoryLessonViewRepository $views,
				EntityHierarchy $hierarchy,
				PositionWriteRule $rule,
				CompletionPropagator $propagator,
				InMemoryEnrollmentRepository $enrollments,
				private \DateTimeImmutable $clock_now
			) {
				parent::__construct( $progress, $views, $hierarchy, $rule, $propagator, $enrollments );
			}

			protected function now(): \DateTimeImmutable {
				return $this->clock_now;
			}
		};
	}

	private function post( int $id, string $type, string $status = 'publish' ): WP_Post {
		$post              = Mockery::mock( 'WP_Post' );
		$post->ID          = $id;
		$post->post_type   = $type;
		$post->post_status = $status;
		assert( $post instanceof WP_Post );
		$this->posts[ $id ] = $post;
		return $post;
	}

	private function request( EntityType $type, int $id, ViewEventType $event, ?int $position = null ): ProgressEventRequest {
		return new ProgressEventRequest( $type, $id, self::VALID_UUID, $event, $position, null );
	}

	public function test_first_event_creates_in_progress_row_and_journal_entry(): void {
		$lesson = $this->post( 200, 'vl_lesson' );
		$course = $this->post( 100, 'vl_course' );
		$this->hierarchy->shouldReceive( 'resolveCourse' )->with( $lesson )->andReturn( $course );

		$this->enrollments->seed(
			[
				'user_id'      => 1,
				'course_id'    => 100,
				'progress_pct' => 25,
			]
		);

		$result = $this->service()->record( 1, $this->request( EntityType::LESSON, 200, ViewEventType::PROGRESS, 60 ) );

		$row = $this->progress->find( 1, EntityType::LESSON, 200 );
		self::assertNotNull( $row );
		self::assertSame( ProgressStatus::IN_PROGRESS, $row->status );
		self::assertSame( 60, $row->position_seconds );

		self::assertSame( 25, $result->course_progress_pct );
		self::assertFalse( $result->lesson_completed );
		self::assertSame( 1, $result->view_id );

		$view = $this->views->find_by_id( 1 );
		self::assertNotNull( $view );
		self::assertSame( 200, $view->lesson_id );
		self::assertNull( $view->topic_id );
		self::assertSame( ViewEventType::PROGRESS, $view->event_type );
	}

	public function test_progress_event_with_smaller_position_preserves_stored_value(): void {
		$lesson = $this->post( 200, 'vl_lesson' );
		$course = $this->post( 100, 'vl_course' );
		$this->hierarchy->shouldReceive( 'resolveCourse' )->andReturn( $course );

		$this->progress->upsert(
			1,
			EntityType::LESSON,
			200,
			100,
			ProgressStatus::IN_PROGRESS,
			240,
			null,
			$this->now
		);

		$this->enrollments->seed(
			[
				'user_id'      => 1,
				'course_id'    => 100,
				'progress_pct' => 0,
			]
		);

		$this->service()->record( 1, $this->request( EntityType::LESSON, 200, ViewEventType::PROGRESS, 120 ) );

		$row = $this->progress->find( 1, EntityType::LESSON, 200 );
		self::assertNotNull( $row );
		self::assertSame( 240, $row->position_seconds );
	}

	public function test_seek_overwrites_smaller_value(): void {
		$lesson = $this->post( 200, 'vl_lesson' );
		$course = $this->post( 100, 'vl_course' );
		$this->hierarchy->shouldReceive( 'resolveCourse' )->andReturn( $course );

		$this->progress->upsert(
			1,
			EntityType::LESSON,
			200,
			100,
			ProgressStatus::IN_PROGRESS,
			240,
			null,
			$this->now
		);

		$this->enrollments->seed(
			[
				'user_id'   => 1,
				'course_id' => 100,
			]
		);

		$this->service()->record( 1, $this->request( EntityType::LESSON, 200, ViewEventType::SEEK, 60 ) );

		$row = $this->progress->find( 1, EntityType::LESSON, 200 );
		self::assertNotNull( $row );
		self::assertSame( 60, $row->position_seconds );
	}

	public function test_complete_event_invokes_propagator_and_returns_its_flags(): void {
		$lesson = $this->post( 200, 'vl_lesson' );
		$course = $this->post( 100, 'vl_course' );
		$this->hierarchy->shouldReceive( 'resolveCourse' )->andReturn( $course );

		$this->propagator
			->shouldReceive( 'propagate' )
			->once()
			->with( 1, 100, $lesson )
			->andReturn( new PropagationResult( true, false, 75, false ) );

		$this->enrollments->seed(
			[
				'user_id'   => 1,
				'course_id' => 100,
			]
		);

		$result = $this->service()->record( 1, $this->request( EntityType::LESSON, 200, ViewEventType::COMPLETE, 600 ) );

		self::assertTrue( $result->lesson_completed );
		self::assertSame( 75, $result->course_progress_pct );

		$row = $this->progress->find( 1, EntityType::LESSON, 200 );
		self::assertNotNull( $row );
		self::assertSame( ProgressStatus::COMPLETED, $row->status );
	}

	public function test_topic_event_resolves_lesson_for_journal_row(): void {
		$topic         = $this->post( 300, 'vl_topic' );
		$parent_lesson = $this->post( 200, 'vl_lesson' );
		$course        = $this->post( 100, 'vl_course' );

		$this->hierarchy->shouldReceive( 'resolveCourse' )->with( $topic )->andReturn( $course );
		$this->hierarchy->shouldReceive( 'resolveLesson' )->with( $topic )->andReturn( $parent_lesson );

		$this->enrollments->seed(
			[
				'user_id'   => 1,
				'course_id' => 100,
			]
		);

		$result = $this->service()->record( 1, $this->request( EntityType::TOPIC, 300, ViewEventType::PROGRESS, 30 ) );

		$view = $this->views->find_by_id( $result->view_id );
		self::assertNotNull( $view );
		self::assertSame( 200, $view->lesson_id );
		self::assertSame( 300, $view->topic_id );
	}

	public function test_re_complete_preserves_original_completed_at(): void {
		$lesson = $this->post( 200, 'vl_lesson' );
		$course = $this->post( 100, 'vl_course' );
		$this->hierarchy->shouldReceive( 'resolveCourse' )->andReturn( $course );

		$original_completed = new \DateTimeImmutable( '2026-04-20 09:00:00', new \DateTimeZone( 'UTC' ) );
		$this->progress->upsert(
			1,
			EntityType::LESSON,
			200,
			100,
			ProgressStatus::COMPLETED,
			500,
			$original_completed,
			$this->now
		);

		$this->propagator
			->shouldReceive( 'propagate' )
			->andReturn( new PropagationResult( false, false, 100, false ) );

		$this->enrollments->seed(
			[
				'user_id'   => 1,
				'course_id' => 100,
			]
		);

		$this->service()->record( 1, $this->request( EntityType::LESSON, 200, ViewEventType::COMPLETE, 600 ) );

		$row = $this->progress->find( 1, EntityType::LESSON, 200 );
		self::assertNotNull( $row );
		self::assertSame( ProgressStatus::COMPLETED, $row->status );
		self::assertNotNull( $row->completed_at );
		self::assertSame(
			$original_completed->format( 'Y-m-d H:i:s' ),
			$row->completed_at->format( 'Y-m-d H:i:s' )
		);

		// Journal row was still inserted (duplicates accepted).
		self::assertNotNull( $this->views->find_by_id( 1 ) );
	}

	public function test_throws_entity_not_found_when_post_missing(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'entity_not_found' );

		$this->service()->record( 1, $this->request( EntityType::LESSON, 999, ViewEventType::PROGRESS, 10 ) );
	}

	public function test_throws_entity_not_found_when_post_type_mismatch(): void {
		$this->post( 200, 'vl_topic' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'entity_not_found' );

		$this->service()->record( 1, $this->request( EntityType::LESSON, 200, ViewEventType::PROGRESS, 10 ) );
	}

	public function test_throws_entity_not_found_when_post_unpublished(): void {
		$this->post( 200, 'vl_lesson', 'draft' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'entity_not_found' );

		$this->service()->record( 1, $this->request( EntityType::LESSON, 200, ViewEventType::PROGRESS, 10 ) );
	}

	public function test_throws_hierarchy_failure_when_course_unresolvable(): void {
		$lesson = $this->post( 200, 'vl_lesson' );
		$this->hierarchy->shouldReceive( 'resolveCourse' )->with( $lesson )->andReturn( null );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'hierarchy_failure' );

		$this->service()->record( 1, $this->request( EntityType::LESSON, 200, ViewEventType::PROGRESS, 10 ) );
	}
}
