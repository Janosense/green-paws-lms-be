<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Learn;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Enrollment\EnrollmentStatus;
use VL\LMS\Domain\Progress\EntityType;
use VL\LMS\Domain\Progress\ProgressStatus;
use VL\LMS\Learn\CurriculumTransformer;
use VL\LMS\Learn\LessonNodeTransformer;
use VL\LMS\Learn\ModuleNodeTransformer;
use VL\LMS\Learn\NextEntityResolver;
use VL\LMS\Learn\ProgressOverlay;
use VL\LMS\Learn\QuizNodeTransformer;
use VL\LMS\Learn\QuizStatusOverlay;
use VL\LMS\Learn\SessionNodeTransformer;
use VL\LMS\Learn\TopicNodeTransformer;
use VL\LMS\Domain\Progress\Progress;
use VL\LMS\Repositories\EnrollmentRepository;
use VL\LMS\Repositories\ProgressRepository;
use VL\LMS\Repositories\QuizAttemptRepository;
use VL\LMS\Tests\Fixtures\InMemoryEnrollmentRepository;
use WP_Post;

final class CurriculumTransformerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array<string, array<int, mixed>> */
	private array $meta = [];

	/** @var array<int, list<WP_Post>> Keyed by course ID. */
	private array $modules_by_course = [];

	/** @var array<int, list<WP_Post>> Keyed by parent post ID (module or course for orphans). */
	private array $lessons_by_parent = [];

	/** @var array<int, list<WP_Post>> Keyed by lesson ID. */
	private array $topics_by_lesson = [];

	/** @var array<int, list<WP_Post>> Keyed by course ID. */
	private array $sessions_by_course = [];

	/** @var array<int, list<WP_Post>> Keyed by parent post ID (course/module/lesson/session). */
	private array $quizzes_by_parent = [];

	/** @var array<int, array{passed: bool, in_progress: bool, submitted_count: int, best_pct: float|null}> Keyed by quiz ID. */
	private array $quiz_status_map = [];

	/** @var array<int, bool> */
	private array $cohort_courses = [];

	/** @var array<int, int> session ID → attendance row count */
	private array $session_attendance = [];

	/** @var Mockery\MockInterface&ProgressRepository */
	private $progress;

	/** @var list<Progress> */
	private array $progress_rows = [];

	private int $progress_list_call_count = 0;

	private InMemoryEnrollmentRepository $enrollments;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->meta                     = [];
		$this->modules_by_course        = [];
		$this->lessons_by_parent        = [];
		$this->topics_by_lesson         = [];
		$this->sessions_by_course       = [];
		$this->quizzes_by_parent        = [];
		$this->quiz_status_map          = [];
		$this->cohort_courses           = [];
		$this->session_attendance       = [];
		$this->progress_rows            = [];
		$this->progress_list_call_count = 0;

		$this->progress = Mockery::mock( ProgressRepository::class );
		$rows           = &$this->progress_rows;
		$counter        = &$this->progress_list_call_count;
		$this->progress->shouldReceive( 'list_for_user_in_course' )
			->andReturnUsing(
				static function () use ( &$rows, &$counter ): array {
					++$counter;
					return $rows;
				}
			);

		$this->enrollments = new InMemoryEnrollmentRepository();

		$meta_ref = &$this->meta;
		Functions\when( 'get_post_meta' )->alias(
			static function ( int $post_id, string $key ) use ( &$meta_ref ): mixed {
				return $meta_ref[ $key ][ $post_id ] ?? '';
			}
		);
		Functions\when( 'get_the_title' )->alias(
			static fn ( WP_Post $p ): string => (string) $p->post_title
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function makeTransformer(): CurriculumTransformer {
		$topic_transformer = new TopicNodeTransformer();
		$quiz_transformer  = $this->quizTransformer();

		$lesson_transformer = new class( $topic_transformer, $quiz_transformer, $this->topics_by_lesson ) extends LessonNodeTransformer {

			/** @param array<int, list<WP_Post>> $topics_by_lesson */
			public function __construct(
				TopicNodeTransformer $topic_transformer,
				QuizNodeTransformer $quiz_transformer,
				private array $topics_by_lesson
			) {
				parent::__construct( $topic_transformer, $quiz_transformer );
			}

			protected function query_child_topics( int $lesson_id ): array {
				return $this->topics_by_lesson[ $lesson_id ] ?? [];
			}
		};

		$module_transformer = new class( $lesson_transformer, $quiz_transformer, $this->lessons_by_parent ) extends ModuleNodeTransformer {

			/** @param array<int, list<WP_Post>> $lessons_by_parent */
			public function __construct(
				LessonNodeTransformer $lesson_transformer,
				QuizNodeTransformer $quiz_transformer,
				private array $lessons_by_parent
			) {
				parent::__construct( $lesson_transformer, $quiz_transformer );
			}

			protected function query_child_lessons( int $parent_id ): array {
				return $this->lessons_by_parent[ $parent_id ] ?? [];
			}
		};

		$session_transformer = new class( $quiz_transformer, $this->session_attendance ) extends SessionNodeTransformer {

			/** @param array<int, int> $session_attendance */
			public function __construct(
				private QuizNodeTransformer $quiz_seam,
				private array $session_attendance
			) {
				// Skip parent constructor — the test seam doesn't use the
				// attendance repository directly.
			}

			public function transform( WP_Post $session, int $user_id, ProgressOverlay $overlay, QuizStatusOverlay $quiz_overlay ): array {
				unset( $overlay );
				$session_id = (int) $session->ID;
				$count      = $this->session_attendance[ $session_id ] ?? 0;
				return [
					'type'               => 'session',
					'id'                 => $session_id,
					'slug'               => (string) $session->post_name,
					'title'              => (string) $session->post_title,
					'session_number'     => 0,
					'scheduled_start'    => null,
					'scheduled_end'      => null,
					'status'             => 'scheduled',
					'is_completed'       => $count > 0,
					'join_url_path'      => '/vl/v1/learn/sessions/' . $session->post_name . '/join',
					'recording_url_path' => null,
					'quizzes'            => $this->quiz_seam->transform_children( $session_id, $quiz_overlay ),
				];
			}
		};

		$quiz_attempts = Mockery::mock( QuizAttemptRepository::class );
		$status_map    = $this->quiz_status_map;
		$quiz_attempts->shouldReceive( 'status_map_for_user_in_course' )->andReturn( $status_map );

		return new class(
			$module_transformer,
			$lesson_transformer,
			$session_transformer,
			$quiz_transformer,
			new NextEntityResolver(),
			$this->progress,
			$quiz_attempts,
			$this->enrollments,
			$this->modules_by_course,
			$this->lessons_by_parent,
			$this->sessions_by_course,
			$this->quizzes_by_parent,
			$this->cohort_courses
		) extends CurriculumTransformer {

			/**
			 * @param array<int, list<WP_Post>> $modules_by_course
			 * @param array<int, list<WP_Post>> $lessons_by_parent
			 * @param array<int, list<WP_Post>> $sessions_by_course
			 * @param array<int, list<WP_Post>> $quizzes_by_parent
			 * @param array<int, bool>          $cohort_courses
			 */
			public function __construct(
				ModuleNodeTransformer $module_transformer,
				LessonNodeTransformer $lesson_transformer,
				SessionNodeTransformer $session_transformer,
				QuizNodeTransformer $quiz_transformer,
				NextEntityResolver $next_resolver,
				ProgressRepository $progress,
				QuizAttemptRepository $quiz_attempts,
				EnrollmentRepository $enrollments,
				private array $modules_by_course,
				private array $lessons_by_parent,
				private array $sessions_by_course,
				private array $quizzes_by_parent,
				private array $cohort_courses
			) {
				parent::__construct(
					$module_transformer,
					$lesson_transformer,
					$session_transformer,
					$quiz_transformer,
					$next_resolver,
					$progress,
					$quiz_attempts,
					$enrollments
				);
			}

			protected function query_child_modules( int $course_id ): array {
				return $this->modules_by_course[ $course_id ] ?? [];
			}

			protected function query_orphan_lessons( int $course_id ): array {
				return $this->lessons_by_parent[ $course_id ] ?? [];
			}

			protected function query_child_sessions( int $course_id ): array {
				return $this->sessions_by_course[ $course_id ] ?? [];
			}

			protected function is_cohort_course( int $course_id ): bool {
				return $this->cohort_courses[ $course_id ] ?? false;
			}
		};
	}

	/**
	 * Quiz transformer test seam shared by every node level — its child
	 * query reads from {@see self::$quizzes_by_parent} keyed by parent post ID.
	 */
	private function quizTransformer(): QuizNodeTransformer {
		return new class( $this->quizzes_by_parent ) extends QuizNodeTransformer {

			/** @param array<int, list<WP_Post>> $quizzes_by_parent */
			public function __construct( private array $quizzes_by_parent ) {
			}

			protected function query_child_quizzes( int $parent_id ): array {
				return $this->quizzes_by_parent[ $parent_id ] ?? [];
			}
		};
	}

	private function post( int $id, string $type, string $slug, string $title, int $menu_order = 1 ): WP_Post {
		$post              = Mockery::mock( 'WP_Post' );
		$post->ID          = $id;
		$post->post_type   = $type;
		$post->post_name   = $slug;
		$post->post_title  = $title;
		$post->post_status = 'publish';
		$post->menu_order  = $menu_order;
		assert( $post instanceof WP_Post );
		return $post;
	}

	public function test_full_assembly_with_modules_orphans_topics_and_progress(): void {
		$course = $this->post( 100, 'vl_course', 'feline-cardio', 'Feline Cardiology' );
		$module = $this->post( 110, 'vl_module', 'module-1-basics', 'Module 1: Basics', 1 );

		$lesson_no_topics = $this->post( 121, 'vl_lesson', 'welcome', 'Welcome', 1 );
		$lesson_topics    = $this->post( 123, 'vl_lesson', 'intro-to-cardiology', 'Intro to Cardiology', 2 );
		$orphan_lesson    = $this->post( 199, 'vl_lesson', 'course-direct-lesson', 'Course-direct lesson', 99 );

		$topic = $this->post( 200, 'vl_topic', 'anatomy-of-feline-heart', 'Anatomy of feline heart', 1 );

		$this->meta['_vl_lesson_duration_seconds'][121]    = 300;
		$this->meta['_vl_lesson_is_preview'][121]          = '1';
		$this->meta['_vl_lesson_duration_seconds'][123]    = 1800;
		$this->meta['_vl_lesson_requires_completion'][123] = '1';
		$this->meta['_vl_lesson_duration_seconds'][199]    = 600;
		$this->meta['_vl_topic_duration_seconds'][200]     = 600;

		$this->modules_by_course[100] = [ $module ];
		$this->lessons_by_parent[110] = [ $lesson_no_topics, $lesson_topics ];
		$this->lessons_by_parent[100] = [ $orphan_lesson ];
		$this->topics_by_lesson[123]  = [ $topic ];

		$completed_at          = new \DateTimeImmutable( '2026-04-02 11:00:00', new \DateTimeZone( 'UTC' ) );
		$this->progress_rows[] = $this->row( EntityType::LESSON, 121, ProgressStatus::COMPLETED, 300, $completed_at );
		$this->progress_rows[] = $this->row( EntityType::TOPIC, 200, ProgressStatus::IN_PROGRESS, 240, null );

		$this->enrollments->seed(
			[
				'user_id'      => 5,
				'course_id'    => 100,
				'status'       => EnrollmentStatus::ACTIVE->value,
				'enrolled_at'  => '2026-04-01 10:15:00',
				'progress_pct' => 42,
			]
		);

		$transformer = $this->makeTransformer();
		$payload     = $transformer->transform( $course, 5 );

		// Course block.
		self::assertSame( 100, $payload['course']['id'] );
		self::assertSame( 'feline-cardio', $payload['course']['slug'] );
		self::assertSame( 'Feline Cardiology', $payload['course']['title'] );
		// 300 (lesson_no_topics own duration) + 600 (topics-summed for lesson 123) + 600 (orphan).
		self::assertSame( 1500, $payload['course']['duration_seconds'] );

		// Enrollment block.
		self::assertSame(
			[
				'status'       => 'active',
				'progress_pct' => 42,
				'enrolled_at'  => '2026-04-01T10:15:00+00:00',
				'completed_at' => null,
			],
			$payload['course']['enrollment']
		);

		// Modules and lessons.
		self::assertCount( 1, $payload['modules'] );
		self::assertCount( 2, $payload['modules'][0]['lessons'] );
		self::assertSame( 'completed', $payload['modules'][0]['lessons'][0]['progress']['status'] );
		self::assertTrue( $payload['modules'][0]['lessons'][1]['has_topics'] );
		self::assertSame( 'in_progress', $payload['modules'][0]['lessons'][1]['topics'][0]['progress']['status'] );

		// Orphans.
		self::assertCount( 1, $payload['orphan_lessons'] );
		self::assertSame( 199, $payload['orphan_lessons'][0]['id'] );

		// next_entity points at the in-progress topic (the first non-completed leaf).
		self::assertSame(
			[
				'type'        => 'topic',
				'id'          => 200,
				'slug'        => 'anatomy-of-feline-heart',
				'lesson_slug' => 'intro-to-cardiology',
			],
			$payload['next_entity']
		);

		// Exactly one progress repository call.
		self::assertSame( 1, $this->progress_list_call_count );
	}

	private function row(
		EntityType $type,
		int $entity_id,
		ProgressStatus $status,
		?int $position_seconds = null,
		?\DateTimeImmutable $completed_at = null
	): Progress {
		$now = new \DateTimeImmutable( '2026-04-28 10:00:00', new \DateTimeZone( 'UTC' ) );
		return new Progress(
			id: $entity_id * 10,
			user_id: 5,
			entity_type: $type,
			entity_id: $entity_id,
			course_id: 100,
			status: $status,
			position_seconds: $position_seconds,
			completed_at: $completed_at,
			last_seen_at: $now,
			created_at: $now,
			updated_at: $now,
		);
	}

	public function test_duration_summing_lesson_with_topics_uses_sum_of_topic_durations(): void {
		$course        = $this->post( 100, 'vl_course', 'c', 'Course' );
		$lesson_plain  = $this->post( 121, 'vl_lesson', 'plain', 'Plain', 1 );
		$lesson_topics = $this->post( 122, 'vl_lesson', 'with-topics', 'With Topics', 2 );
		$topic_a       = $this->post( 201, 'vl_topic', 'a', 'A', 1 );
		$topic_b       = $this->post( 202, 'vl_topic', 'b', 'B', 2 );

		$this->meta['_vl_lesson_duration_seconds'][121] = 300;
		// Lesson 122 own duration is 1800, but it has topics — should be ignored.
		$this->meta['_vl_lesson_duration_seconds'][122] = 1800;
		$this->meta['_vl_topic_duration_seconds'][201]  = 120;
		$this->meta['_vl_topic_duration_seconds'][202]  = 180;

		$this->lessons_by_parent[100] = [ $lesson_plain, $lesson_topics ];
		$this->topics_by_lesson[122]  = [ $topic_a, $topic_b ];

		$transformer = $this->makeTransformer();
		$payload     = $transformer->transform( $course, 5 );

		// 300 + (120 + 180) = 600.
		self::assertSame( 600, $payload['course']['duration_seconds'] );
	}

	public function test_empty_curriculum_yields_zero_duration_and_null_next_entity(): void {
		$course = $this->post( 100, 'vl_course', 'empty', 'Empty' );

		$transformer = $this->makeTransformer();
		$payload     = $transformer->transform( $course, 5 );

		self::assertSame( 0, $payload['course']['duration_seconds'] );
		self::assertSame( [], $payload['modules'] );
		self::assertSame( [], $payload['orphan_lessons'] );
		self::assertNull( $payload['next_entity'] );
	}

	public function test_enrollment_payload_completed_at_iso_when_set(): void {
		$course = $this->post( 100, 'vl_course', 'c', 'Course' );

		$this->enrollments->seed(
			[
				'user_id'      => 5,
				'course_id'    => 100,
				'status'       => EnrollmentStatus::COMPLETED->value,
				'enrolled_at'  => '2026-04-01 10:00:00',
				'completed_at' => '2026-04-15 12:00:00',
				'progress_pct' => 100,
			]
		);

		$transformer = $this->makeTransformer();
		$payload     = $transformer->transform( $course, 5 );

		self::assertSame(
			'2026-04-15T12:00:00+00:00',
			$payload['course']['enrollment']['completed_at']
		);
	}

	public function test_enrollment_payload_null_when_no_row(): void {
		$course = $this->post( 100, 'vl_course', 'c', 'Course' );

		$transformer = $this->makeTransformer();
		$payload     = $transformer->transform( $course, 5 );

		self::assertNull( $payload['course']['enrollment'] );
	}

	public function test_next_entity_walks_into_modules_then_orphans(): void {
		$course        = $this->post( 100, 'vl_course', 'c', 'Course' );
		$module        = $this->post( 110, 'vl_module', 'm', 'M', 1 );
		$module_lesson = $this->post( 121, 'vl_lesson', 'in-module', 'In Module', 1 );
		$orphan_lesson = $this->post( 122, 'vl_lesson', 'orphan', 'Orphan', 99 );

		$this->modules_by_course[100] = [ $module ];
		$this->lessons_by_parent[110] = [ $module_lesson ];
		$this->lessons_by_parent[100] = [ $orphan_lesson ];

		$completed_at          = new \DateTimeImmutable( '2026-04-01 10:00:00', new \DateTimeZone( 'UTC' ) );
		$this->progress_rows[] = $this->row( EntityType::LESSON, 121, ProgressStatus::COMPLETED, null, $completed_at );

		$transformer = $this->makeTransformer();
		$payload     = $transformer->transform( $course, 5 );

		self::assertNotNull( $payload['next_entity'] );
		self::assertSame( 'orphan', $payload['next_entity']['slug'] );
		self::assertSame( 'lesson', $payload['next_entity']['type'] );
	}

	public function test_cohort_course_renders_sessions_as_top_level_leaves(): void {
		$course    = $this->post( 100, 'vl_course', 'c', 'Course' );
		$session_a = $this->post( 400, 'vl_session', 'session-1', 'Session 1' );
		$session_b = $this->post( 401, 'vl_session', 'session-2', 'Session 2' );

		$this->cohort_courses[100]     = true;
		$this->sessions_by_course[100] = [ $session_a, $session_b ];

		$payload = $this->makeTransformer()->transform( $course, 5 );

		self::assertCount( 2, $payload['sessions'] );
		self::assertSame( 'session', $payload['sessions'][0]['type'] );
		self::assertSame( 'session-1', $payload['sessions'][0]['slug'] );
	}

	public function test_self_paced_course_omits_sessions_even_if_data_anomaly_exists(): void {
		$course         = $this->post( 100, 'vl_course', 'c', 'Course' );
		$orphan_session = $this->post( 400, 'vl_session', 'orphan', 'Orphan' );

		$this->cohort_courses[100]     = false;
		$this->sessions_by_course[100] = [ $orphan_session ];

		$payload = $this->makeTransformer()->transform( $course, 5 );

		self::assertSame( [], $payload['sessions'] );
	}

	public function test_next_entity_picks_session_when_modules_complete_and_session_unattended(): void {
		$course  = $this->post( 100, 'vl_course', 'c', 'Course' );
		$lesson  = $this->post( 200, 'vl_lesson', 'lesson-1', 'Lesson 1' );
		$session = $this->post( 400, 'vl_session', 'session-1', 'Session 1' );

		$this->lessons_by_parent[100]  = [ $lesson ];
		$this->cohort_courses[100]     = true;
		$this->sessions_by_course[100] = [ $session ];

		$completed_at          = new \DateTimeImmutable( '2026-04-01 10:00:00', new \DateTimeZone( 'UTC' ) );
		$this->progress_rows[] = $this->row( EntityType::LESSON, 200, ProgressStatus::COMPLETED, null, $completed_at );

		$payload = $this->makeTransformer()->transform( $course, 5 );

		self::assertNotNull( $payload['next_entity'] );
		self::assertSame( 'session', $payload['next_entity']['type'] );
		self::assertSame( 'session-1', $payload['next_entity']['slug'] );
	}

	public function test_course_level_quizzes_surface_and_next_entity_points_at_unpassed_final_exam(): void {
		$course     = $this->post( 100, 'vl_course', 'c', 'Course' );
		$lesson     = $this->post( 200, 'vl_lesson', 'lesson-1', 'Lesson 1' );
		$final_exam = $this->post( 700, 'vl_quiz', 'final-exam', 'Final Exam', 1 );

		$this->lessons_by_parent[100]              = [ $lesson ];
		$this->quizzes_by_parent[100]              = [ $final_exam ];
		$this->meta['_vl_quiz_is_final_exam'][700] = '1';

		$completed_at          = new \DateTimeImmutable( '2026-04-01 10:00:00', new \DateTimeZone( 'UTC' ) );
		$this->progress_rows[] = $this->row( EntityType::LESSON, 200, ProgressStatus::COMPLETED, null, $completed_at );

		// No attempt rows → final exam is not passed.
		$payload = $this->makeTransformer()->transform( $course, 5 );

		self::assertCount( 1, $payload['course_quizzes'] );
		self::assertSame( 700, $payload['course_quizzes'][0]['id'] );
		self::assertTrue( $payload['course_quizzes'][0]['is_final_exam'] );
		self::assertSame( 'not_started', $payload['course_quizzes'][0]['status'] );

		self::assertSame(
			[
				'type' => 'quiz',
				'id'   => 700,
				'slug' => 'final-exam',
			],
			$payload['next_entity']
		);
	}

	public function test_passed_course_quiz_is_skipped_by_next_entity(): void {
		$course     = $this->post( 100, 'vl_course', 'c', 'Course' );
		$final_exam = $this->post( 700, 'vl_quiz', 'final-exam', 'Final Exam', 1 );

		$this->quizzes_by_parent[100] = [ $final_exam ];
		$this->quiz_status_map[700]   = [
			'passed'          => true,
			'in_progress'     => false,
			'submitted_count' => 1,
			'best_pct'        => 92.0,
		];

		$payload = $this->makeTransformer()->transform( $course, 5 );

		self::assertSame( 'passed', $payload['course_quizzes'][0]['status'] );
		self::assertSame( 92.0, $payload['course_quizzes'][0]['best_score_pct'] );
		self::assertNull( $payload['next_entity'] );
	}
}
