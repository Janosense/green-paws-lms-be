<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Admin\StudyTime;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Admin\StudyTime\AnalyticsSection;
use VL\LMS\StudyTime\Reports\StudyTimeReportQuery;

/**
 * The «Час навчання» table on the Аналітика page, and its course selector —
 * the one control on this screen, so the tests read the state after a course
 * is chosen, not only the markup at first paint.
 */
final class AnalyticsSectionTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->alias( static fn ( string $v ): string => htmlspecialchars( $v, ENT_QUOTES ) );
		Functions\when( 'esc_attr' )->alias( static fn ( string $v ): string => htmlspecialchars( $v, ENT_QUOTES ) );
		Functions\when( 'number_format_i18n' )->alias( static fn ( $n, $d = 0 ): string => number_format( (float) $n, (int) $d, ',', ' ' ) );
		Functions\when( 'selected' )->alias(
			static fn ( $a, $b, $echo = true ): string => (string) $a === (string) $b ? " selected='selected'" : ''
		);
		Functions\when( 'submit_button' )->alias(
			static function ( string $text = '', string $type = '', string $name = '', bool $wrap = true ): void {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Test double for a WP function; the label is a fixture string.
				echo '<button class="button">' . $text . '</button>';
			}
		);
		Functions\when( 'absint' )->alias( static fn ( $v ): int => abs( (int) $v ) );
		Functions\when( 'wp_unslash' )->returnArg();

		$_GET = [];
	}

	protected function tearDown(): void {
		$_GET = [];
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param array<string, mixed> $overrides
	 * @return array<string, mixed>
	 */
	private static function course_report( array $overrides = [] ): array {
		return array_merge(
			[
				'learners_with_time' => 0,
				'avg_total'          => 0,
				'avg_video'          => 0,
				'avg_reading'        => 0,
				'avg_quiz'           => 0,
				'avg_session'        => 0,
				'lessons'            => [],
			],
			$overrides
		);
	}

	/**
	 * @param array<int, string>               $courses
	 * @param array<int, array<string, mixed>> $reports `course id => report`
	 */
	private function render( array $courses, array $reports ): string {
		$query = Mockery::mock( StudyTimeReportQuery::class );
		$query->shouldReceive( 'for_course' )
			->andReturnUsing( static fn ( int $course_id ): array => $reports[ $course_id ] ?? self::course_report() );

		ob_start();
		( new TestableAnalyticsSection( $query, $courses ) )->render();
		return (string) ob_get_clean();
	}

	public function test_the_overview_lists_every_published_course_with_its_averages(): void {
		$output = $this->render(
			[
				101 => 'Анестезія',
				202 => 'Кесарів розтин',
			],
			[
				101 => self::course_report(
					[
						'learners_with_time' => 3,
						'avg_total'          => 4716,
						'avg_video'          => 600,
						'avg_reading'        => 300,
						'avg_quiz'           => 480,
						'avg_session'        => 3336,
					]
				),
			]
		);

		foreach ( [ 'Курс', 'Студентів з часом', 'Середній час', 'Відео', 'Читання', 'Тести', 'Сесії' ] as $label ) {
			self::assertStringContainsString( '<th>' . $label . '</th>', $output );
		}
		self::assertStringContainsString( 'Анестезія', $output );
		self::assertStringContainsString( '<td>3</td>', $output, 'learners with time is a plain count' );
		self::assertStringContainsString( '1 год 18 хв', $output );
		self::assertStringContainsString( 'Кесарів розтин', $output );
		self::assertStringContainsString( '<td>0 хв</td>', $output, 'a course nobody studied still gets its row' );
	}

	public function test_choosing_a_course_shows_that_courses_lessons_and_not_another_courses(): void {
		$_GET = [ AnalyticsSection::COURSE_PARAM => '202' ];

		$output = $this->render(
			[
				101 => 'Анестезія',
				202 => 'Кесарів розтин',
			],
			[
				101 => self::course_report(
					[
						'learners_with_time' => 2,
						'avg_total'          => 600,
						'lessons'            => [
							[
								'lesson_id' => 1,
								'title'     => 'Урок іншого курсу',
								'avg_total' => 600,
								'learners'  => 2,
							],
						],
					]
				),
				202 => self::course_report(
					[
						'learners_with_time' => 4,
						'avg_total'          => 900,
						'lessons'            => [
							[
								'lesson_id' => 611,
								'title'     => 'Епідуральна анестезія',
								'avg_total' => 720,
								'learners'  => 3,
							],
						],
					]
				),
			]
		);

		self::assertStringContainsString( "value='202' selected='selected'", str_replace( '"', "'", $output ) );
		self::assertStringContainsString( '<th>Урок</th>', $output );
		self::assertStringContainsString( 'Епідуральна анестезія', $output );
		self::assertStringNotContainsString( 'Урок іншого курсу', $output );
		self::assertStringContainsString( '12 хв', $output, "the chosen course's lesson average" );
	}

	public function test_without_a_choice_no_lesson_table_is_rendered(): void {
		$output = $this->render(
			[ 202 => 'Кесарів розтин' ],
			[
				202 => self::course_report(
					[
						'learners_with_time' => 4,
						'avg_total'          => 900,
						'lessons'            => [
							[
								'lesson_id' => 611,
								'title'     => 'Епідуральна анестезія',
								'avg_total' => 720,
								'learners'  => 3,
							],
						],
					]
				),
			]
		);

		self::assertStringNotContainsString( '<th>Урок</th>', $output );
		self::assertStringNotContainsString( 'Епідуральна анестезія', $output );
	}

	public function test_the_form_returns_to_the_analytics_page(): void {
		$output = $this->render(
			[ 202 => 'Кесарів розтин' ],
			[
				202 => self::course_report(
					[
						'learners_with_time' => 1,
						'avg_total'          => 60,
					]
				),
			]
		);

		self::assertStringContainsString( 'name="page" value="vl-lms-analytics"', $output );
	}

	public function test_a_course_title_is_escaped_in_the_table_and_in_the_option(): void {
		$output = $this->render(
			[ 202 => 'Курс <script>alert("x")</script>' ],
			[
				202 => self::course_report(
					[
						'learners_with_time' => 1,
						'avg_total'          => 60,
					]
				),
			]
		);

		self::assertStringNotContainsString( '<script>', $output );
		self::assertSame( 2, substr_count( $output, '&lt;script&gt;' ), 'once in the table, once in the option' );
	}

	public function test_no_course_with_time_shows_the_empty_state_and_no_table(): void {
		$output = $this->render(
			[
				101 => 'Анестезія',
				202 => 'Кесарів розтин',
			],
			[]
		);

		self::assertStringContainsString( 'Час навчання ще не зафіксовано.', $output );
		self::assertStringNotContainsString( '<table', $output );
		self::assertStringNotContainsString( '<select', $output, 'nothing to detail, so nothing to choose' );
	}

	public function test_a_chosen_course_without_lesson_rows_says_so_instead_of_an_empty_table(): void {
		// Its time is all quizzes and sessions — data, not silence
		// (`docs/DECISIONS.md` 2026-09-16).
		$_GET = [ AnalyticsSection::COURSE_PARAM => '202' ];

		$output = $this->render(
			[ 202 => 'Кесарів розтин' ],
			[
				202 => self::course_report(
					[
						'learners_with_time' => 2,
						'avg_total'          => 5000,
						'avg_quiz'           => 800,
						'avg_session'        => 4200,
					]
				),
			]
		);

		self::assertStringContainsString( '<th>Курс</th>', $output, 'the overview is still there' );
		self::assertStringNotContainsString( '<th>Урок</th>', $output );
		self::assertStringContainsString( 'Час навчання ще не зафіксовано.', $output );
	}
}
