<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Admin\StudyTime;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Admin\StudyTime\StudentDetailSection;
use VL\LMS\Domain\Enrollment\Enrollment;
use VL\LMS\StudyTime\Reports\StudyTimeReportQuery;

/**
 * The «Час навчання» card: what an administrator reads off a student's
 * Аналітика tab, and the two states that are easy to confuse — a learner
 * with no time at all, and a learner whose time is all quizzes and sessions.
 */
final class StudentDetailSectionTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->alias( static fn ( string $v ): string => htmlspecialchars( $v, ENT_QUOTES ) );
		Functions\when( 'number_format_i18n' )->alias( static fn ( $n, $d = 0 ): string => number_format( (float) $n, (int) $d, ',', ' ' ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function stub_course_title( string $title ): void {
		Functions\when( 'get_post' )->alias(
			static function () use ( $title ) {
				$post             = Mockery::mock( 'WP_Post' );
				$post->post_title = $title;
				return $post;
			}
		);
	}

	private function enrollment( int $course_id = 321, ?string $started_at = '2026-09-01 08:00:00' ): Enrollment {
		return Enrollment::from_row(
			[
				'id'            => 1,
				'user_id'       => 7,
				'course_id'     => $course_id,
				'status'        => 'active',
				'source'        => 'manual',
				'enrolled_at'   => '2026-08-01 10:00:00',
				'started_at'    => $started_at,
				'completed_at'  => null,
				'expires_at'    => null,
				'revoked_at'    => null,
				'revoked_by'    => null,
				'revoke_reason' => null,
				'progress_pct'  => 40,
				'created_at'    => '2026-08-01 10:00:00',
				'updated_at'    => '2026-08-01 10:00:00',
			]
		);
	}

	/**
	 * @param array<string, mixed> $report
	 */
	private function section( array $report ): StudentDetailSection {
		$reports = Mockery::mock( StudyTimeReportQuery::class );
		$reports->shouldReceive( 'for_enrollment' )->andReturn( $report );
		return new StudentDetailSection( $reports );
	}

	/**
	 * @param array<string, mixed> $overrides
	 * @return array<string, mixed>
	 */
	private static function report( array $overrides = [] ): array {
		return array_merge(
			[
				'total'   => 0,
				'video'   => 0,
				'reading' => 0,
				'quiz'    => 0,
				'session' => 0,
				'lessons' => [],
			],
			$overrides
		);
	}

	/**
	 * @param list<Enrollment>     $enrollments
	 * @param array<string, mixed> $report
	 */
	private function render( array $enrollments, array $report ): string {
		ob_start();
		$this->section( $report )->render( 7, $enrollments );
		return (string) ob_get_clean();
	}

	public function test_it_renders_the_heading_and_every_column_of_the_course_table(): void {
		$this->stub_course_title( 'Кесарів розтин' );

		$output = $this->render(
			[ $this->enrollment() ],
			self::report(
				[
					'total'   => 4716,
					'video'   => 15,
					'reading' => 21,
					'quiz'    => 480,
					'session' => 4200,
				]
			)
		);

		self::assertStringContainsString( 'Час навчання', $output );
		foreach ( [ 'Курс', 'Відео', 'Читання', 'Тести', 'Сесії', 'Разом', 'Перша активність' ] as $label ) {
			self::assertStringContainsString( '<th>' . $label . '</th>', $output );
		}
		self::assertStringContainsString( 'Кесарів розтин', $output );
		self::assertStringContainsString( '1 год 18 хв', $output, 'the total is formatted, not printed as seconds' );
		self::assertStringContainsString( '8 хв', $output, 'the quiz figure' );
	}

	public function test_a_course_with_lessons_gets_a_collapsible_per_lesson_table(): void {
		$this->stub_course_title( 'Кесарів розтин' );

		$output = $this->render(
			[ $this->enrollment() ],
			self::report(
				[
					'total'   => 900,
					'video'   => 600,
					'reading' => 300,
					'lessons' => [
						[
							'lesson_id' => 611,
							'title'     => 'Епідуральна анестезія',
							'video'     => 600,
							'reading'   => 120,
							'total'     => 720,
						],
						[
							'lesson_id' => 612,
							'title'     => 'Післяопераційне знеболення',
							'video'     => 0,
							'reading'   => 180,
							'total'     => 180,
						],
					],
				]
			)
		);

		self::assertStringContainsString( '<details>', $output );
		self::assertStringContainsString( '<summary>Кесарів розтин</summary>', $output );
		self::assertStringContainsString( '<th>Урок</th>', $output );
		self::assertStringContainsString( 'Епідуральна анестезія', $output );
		self::assertStringContainsString( 'Післяопераційне знеболення', $output );
		self::assertStringContainsString( '12 хв', $output, 'the first lesson total' );
		self::assertStringContainsString( '3 хв', $output, 'the second lesson total' );
	}

	public function test_a_course_title_is_escaped(): void {
		$this->stub_course_title( 'Курс <script>alert("x")</script>' );

		$output = $this->render(
			[ $this->enrollment() ],
			self::report(
				[
					'total'   => 60,
					'reading' => 60,
				]
			)
		);

		self::assertStringNotContainsString( '<script>', $output );
		self::assertStringContainsString( '&lt;script&gt;', $output );
	}

	public function test_a_learner_who_never_started_shows_the_dash_not_the_enrolment_date(): void {
		$this->stub_course_title( 'Кесарів розтин' );

		$output = $this->render(
			[ $this->enrollment( 321, null ) ],
			self::report(
				[
					'total'   => 60,
					'reading' => 60,
				]
			)
		);

		self::assertStringContainsString( '<td>—</td>', $output );
		self::assertStringNotContainsString( '2026-08-01 10:00:00', $output, 'enrolled is not started' );
	}

	public function test_the_first_activity_column_shows_the_stamp_when_there_is_one(): void {
		$this->stub_course_title( 'Кесарів розтин' );

		$output = $this->render(
			[ $this->enrollment( 321, '2026-09-01 08:00:00' ) ],
			self::report(
				[
					'total'   => 60,
					'reading' => 60,
				]
			)
		);

		self::assertStringContainsString( '<td>2026-09-01 08:00:00</td>', $output );
	}

	public function test_a_learner_with_no_recorded_time_gets_the_empty_state_and_no_table(): void {
		$this->stub_course_title( 'Кесарів розтин' );

		$output = $this->render( [ $this->enrollment() ], self::report() );

		self::assertStringContainsString( 'Час навчання ще не зафіксовано.', $output );
		self::assertStringNotContainsString( '<table', $output );
	}

	public function test_time_that_is_only_quizzes_and_sessions_is_data_not_silence(): void {
		// The report returns no lesson rows for it, which must not read as
		// "nothing recorded" (`docs/DECISIONS.md` 2026-09-16).
		$this->stub_course_title( 'Кесарів розтин' );

		$output = $this->render(
			[ $this->enrollment() ],
			self::report(
				[
					'total'   => 5000,
					'quiz'    => 800,
					'session' => 4200,
				]
			)
		);

		self::assertStringNotContainsString( 'Час навчання ще не зафіксовано.', $output );
		self::assertStringContainsString( '<table', $output );
		self::assertStringNotContainsString( '<details>', $output, 'no lesson rows, so nothing to open' );
	}

	public function test_a_student_with_no_enrollments_shows_the_empty_state(): void {
		$output = $this->render( [], self::report() );

		self::assertStringContainsString( 'Час навчання ще не зафіксовано.', $output );
		self::assertStringNotContainsString( '<table', $output );
	}
}
