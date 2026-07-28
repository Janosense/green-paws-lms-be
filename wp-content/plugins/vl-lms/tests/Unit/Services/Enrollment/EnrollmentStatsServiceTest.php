<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Services\Enrollment;

use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Progress\EntityType;
use VL\LMS\Domain\Progress\ProgressStatus;
use VL\LMS\Domain\Quiz\QuizAttempt;
use VL\LMS\Domain\Quiz\QuizAttemptStatus;
use VL\LMS\Learn\Progression\CurriculumCounts;
use VL\LMS\Learn\Progression\CurriculumOrder;
use VL\LMS\Services\Enrollment\EnrollmentStatsService;
use VL\LMS\Tests\Fixtures\InMemoryProgressRepository;
use VL\LMS\Tests\Fixtures\InMemoryQuizAttemptRepository;

final class EnrollmentStatsServiceTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private InMemoryProgressRepository $progress;

	private InMemoryQuizAttemptRepository $attempts;

	/** @var Mockery\MockInterface&CurriculumOrder */
	private $order;

	private EnrollmentStatsService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->progress = new InMemoryProgressRepository();
		$this->attempts = new InMemoryQuizAttemptRepository();
		$this->order    = Mockery::mock( CurriculumOrder::class );

		$this->service = new EnrollmentStatsService( $this->order, $this->progress, $this->attempts );
	}

	private static function utc( string $value ): \DateTimeImmutable {
		return new \DateTimeImmutable( $value, new \DateTimeZone( 'UTC' ) );
	}

	private function counts_are(
		int $course_id,
		int $modules = 0,
		int $lessons = 0,
		int $topics = 0,
		int $quizzes = 0,
		bool $has_final_exam = false
	): void {
		$this->order->shouldReceive( 'counts_for' )
			->with( $course_id )
			->andReturn( new CurriculumCounts( $modules, $lessons, $topics, $quizzes, $has_final_exam ) );
	}

	private function completed( int $user_id, EntityType $type, int $entity_id, int $course_id ): void {
		$this->progress->upsert(
			$user_id,
			$type,
			$entity_id,
			$course_id,
			ProgressStatus::COMPLETED,
			null,
			self::utc( '2026-05-01 10:00:00' ),
			self::utc( '2026-05-01 10:00:00' )
		);
	}

	private function passed_attempt( int $user_id, int $quiz_id, int $course_id, string $started_at = '2026-05-01 10:00:00' ): void {
		$this->attempts->insert(
			new QuizAttempt(
				0,
				$user_id,
				$quiz_id,
				$course_id,
				QuizAttemptStatus::SUBMITTED,
				self::utc( $started_at ),
				self::utc( $started_at ),
				600,
				120,
				90,
				100,
				true,
				70,
				[ 201 ],
				self::utc( $started_at ),
				self::utc( $started_at )
			)
		);
	}

	public function test_empty_course_list_returns_empty_array(): void {
		self::assertSame( [], $this->service->for_user_in_courses( 5, [] ) );
	}

	public function test_zero_fills_every_requested_course(): void {
		$this->counts_are( 7 );

		self::assertSame(
			[
				7 => [
					'modules' => [
						'total'     => 0,
						'completed' => 0,
					],
					'lessons' => [
						'total'     => 0,
						'completed' => 0,
					],
					'topics'  => [
						'total'     => 0,
						'completed' => 0,
					],
					'quizzes' => [
						'total'          => 0,
						'passed'         => 0,
						'has_final_exam' => false,
					],
				],
			],
			$this->service->for_user_in_courses( 5, [ 7 ] )
		);
	}

	public function test_maps_entity_type_buckets_and_passed_quizzes_per_course(): void {
		$this->counts_are( 7, modules: 2, lessons: 4, topics: 6, quizzes: 3, has_final_exam: true );
		$this->counts_are( 8, lessons: 1 );

		$this->completed( 5, EntityType::MODULE, 10, 7 );
		$this->completed( 5, EntityType::LESSON, 100, 7 );
		$this->completed( 5, EntityType::LESSON, 101, 7 );
		$this->completed( 5, EntityType::TOPIC, 1000, 7 );
		$this->completed( 5, EntityType::SESSION, 300, 7 ); // ignored — no session row
		$this->completed( 5, EntityType::LESSON, 200, 8 );
		$this->completed( 6, EntityType::LESSON, 100, 7 );  // foreign user
		$this->passed_attempt( 5, 500, 7 );
		$this->passed_attempt( 5, 501, 7 );

		$stats = $this->service->for_user_in_courses( 5, [ 7, 8 ] );

		self::assertSame(
			[
				'modules' => [
					'total'     => 2,
					'completed' => 1,
				],
				'lessons' => [
					'total'     => 4,
					'completed' => 2,
				],
				'topics'  => [
					'total'     => 6,
					'completed' => 1,
				],
				'quizzes' => [
					'total'          => 3,
					'passed'         => 2,
					'has_final_exam' => true,
				],
			],
			$stats[7]
		);
		self::assertSame(
			[
				'total'     => 1,
				'completed' => 1,
			],
			$stats[8]['lessons']
		);
	}

	/**
	 * Orphan rows for since-deleted entities must never surface
	 * `completed > total` to any consumer.
	 */
	public function test_completed_and_passed_are_capped_at_totals(): void {
		$this->counts_are( 7, lessons: 1, quizzes: 1 );

		$this->completed( 5, EntityType::LESSON, 100, 7 );
		$this->completed( 5, EntityType::LESSON, 101, 7 ); // since-unpublished lesson
		$this->passed_attempt( 5, 500, 7 );
		$this->passed_attempt( 5, 501, 7 ); // since-deleted quiz

		$stats = $this->service->for_user_in_courses( 5, [ 7 ] );

		self::assertSame( 1, $stats[7]['lessons']['completed'] );
		self::assertSame( 1, $stats[7]['quizzes']['passed'] );
	}

	public function test_pre_reset_quiz_passes_do_not_count(): void {
		$this->counts_are( 7, quizzes: 2 );

		$this->passed_attempt( 5, 500, 7, '2026-04-28 10:00:00' );
		$this->passed_attempt( 5, 501, 7, '2026-05-02 10:00:00' );
		$this->attempts->set_progress_reset_at( 5, 7, self::utc( '2026-05-01 00:00:00' ) );

		$stats = $this->service->for_user_in_courses( 5, [ 7 ] );

		self::assertSame( 1, $stats[7]['quizzes']['passed'] );
	}

	public function test_for_user_in_course_returns_the_single_stats_array(): void {
		$this->counts_are( 7, modules: 1, lessons: 2 );

		$stats = $this->service->for_user_in_course( 5, 7 );

		self::assertSame( 1, $stats['modules']['total'] );
		self::assertSame( 2, $stats['lessons']['total'] );
	}
}
