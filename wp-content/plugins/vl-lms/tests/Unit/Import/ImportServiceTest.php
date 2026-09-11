<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Import;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Import\Convert\CourseHtmlBuilder;
use VL\LMS\Import\Convert\ImageRef;
use VL\LMS\Import\Convert\LessonHtmlBuilder;
use VL\LMS\Import\Convert\MarkdownToHtml;
use VL\LMS\Import\Convert\ModuleHtmlBuilder;
use VL\LMS\Import\Document\CourseVariant;
use VL\LMS\Import\ImportService;
use VL\LMS\Import\Issue\ImportIssue;
use VL\LMS\Import\Parser\CourseDocumentParser;
use VL\LMS\Import\Parser\FrontMatterParser;
use VL\LMS\Import\Parser\QuizBlockParser;
use VL\LMS\Import\Plan\ImportSource;
use VL\LMS\Import\Plan\LessonPlan;
use VL\LMS\Import\Plan\QuestionPlan;
use VL\LMS\Import\Validation\CourseValidator;

final class ImportServiceTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const FIXTURES = __DIR__ . '/../../Fixtures/Import/';

	/**
	 * @var list<string>
	 */
	private array $temp_files = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'esc_html' )->alias( static fn ( string $text ): string => htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ) );
		Functions\when( 'term_exists' )->justReturn(
			[
				'term_id'          => 7,
				'term_taxonomy_id' => 7,
			]
		);
	}

	protected function tearDown(): void {
		foreach ( $this->temp_files as $path ) {
			unlink( $path );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_a_valid_course_with_modules_becomes_a_plan(): void {
		$result = $this->service()->analyse( self::FIXTURES . 'course-with-modules.md' );
		$plan   = $result->plan;

		self::assertNotNull( $plan );
		self::assertSame( $result->issues, $plan->issues );
		self::assertSame( [ CourseValidator::STRUCTURE_IGNORED ], $plan->issues->codes() );
		self::assertSame(
			[
				'modules'   => 2,
				'lessons'   => 3,
				'quizzes'   => 2,
				'questions' => 4,
				'images'    => 1,
			],
			$plan->counts()
		);

		self::assertSame( CourseVariant::MODULES, $plan->variant );
		self::assertSame( 'Анестезія при кесаревому розтині: базовий курс', $plan->course->title );
		self::assertSame( 'anesthesia-cesarean-basics', $plan->course->slug );
		self::assertSame( file_get_contents( self::FIXTURES . 'convert/course.html' ), $plan->course->html );
		self::assertSame( 'advanced', $plan->course->difficulty_slug );
		self::assertSame( 'anesthesia', $plan->course->category_slug );
		self::assertSame( [ 'cesarean', 'anesthesia' ], $plan->course->tag_slugs );
		self::assertSame( 1.5, $plan->course->duration_hours );
		self::assertSame( 70, $plan->course->pass_percent );

		[ $first, $second ] = $plan->modules;
		self::assertSame( [ 'Підготовка до анестезії', "<p>Підготовка до анестезії</p>\n", 1 ], [ $first->title, $first->html, $first->menu_order ] );
		self::assertSame( [ 'Оцінка пацієнтки', 'Премедикація' ], array_map( static fn ( LessonPlan $lesson ): string => $lesson->title, $first->lessons ) );
		self::assertSame( [ 1, 2 ], array_map( static fn ( LessonPlan $lesson ): int => $lesson->menu_order, $first->lessons ) );
		self::assertSame( file_get_contents( self::FIXTURES . 'convert/lesson.html' ), $first->lessons[0]->html );
		self::assertSame( [ 'Ведення анестезії', 2 ], [ $second->title, $second->menu_order ] );
		self::assertNull( $second->quiz );

		$quiz = $first->quiz;
		self::assertNotNull( $quiz );
		self::assertSame( [ 'Тест модуля 1', false ], [ $quiz->title, $quiz->is_final_exam ] );
		self::assertEquals(
			new QuestionPlan(
				'Який препарат найчастіше обирають для індукції?',
				1,
				QuestionPlan::SINGLE_CHOICE,
				[
					[
						'text'       => 'Кетамін',
						'is_correct' => false,
					],
					[
						'text'       => 'Пропофол',
						'is_correct' => true,
					],
					[
						'text'       => 'Діазепам',
						'is_correct' => false,
					],
					[
						'text'       => 'Ксилазин',
						'is_correct' => false,
					],
				],
				'пропофол забезпечує швидку індукцію і пробудження (Урок 1.2).'
			),
			$quiz->questions[0]
		);
		self::assertSame( [ QuestionPlan::MULTIPLE_CHOICE, 2, null ], [ $quiz->questions[1]->type, $quiz->questions[1]->menu_order, $quiz->questions[1]->explanation ] );

		self::assertSame( [ 'Підсумковий тест', true, 2 ], [ $plan->final_quiz->title, $plan->final_quiz->is_final_exam, count( $plan->final_quiz->questions ) ] );
		self::assertEquals(
			[ new ImageRef( 'assets/anesthesia-cesarean-basics/monitor.png', 'assets/anesthesia-cesarean-basics/monitor.png', 'Монітор під час операції', 71 ) ],
			$plan->images
		);
		self::assertEquals(
			new ImportSource( 'anesthesia-cesarean-basics', 1, 'draft', 'Іваненко Олена', 'Ветеринарна клініка «Лапа»', 'webinar', 'Кесарів розтин. Анестезіологічний супровід', '2025-11-20' ),
			$plan->source
		);
	}

	public function test_a_course_without_modules_hangs_lessons_on_the_course_and_takes_the_default_pass_percent(): void {
		$plan = $this->service( 80 )->analyse( self::FIXTURES . 'course-flat.md' )->plan;

		self::assertNotNull( $plan );
		self::assertSame( CourseVariant::FLAT, $plan->variant );
		self::assertSame( [], $plan->modules );
		self::assertSame( [ 'Оцінка болю', 'План знеболення' ], array_map( static fn ( LessonPlan $lesson ): string => $lesson->title, $plan->lessons ) );
		self::assertSame( [ 1, 2 ], array_map( static fn ( LessonPlan $lesson ): int => $lesson->menu_order, $plan->lessons ) );
		self::assertSame( 'basic', $plan->course->difficulty_slug );
		self::assertSame( 80, $plan->course->pass_percent );
		self::assertNull( $plan->course->duration_hours );
		self::assertNull( $plan->course->category_slug );
		self::assertSame( [], $plan->course->tag_slugs );
		self::assertEquals( new ImportSource( 'pain-relief-after-spay', 2, 'review', 'Іваненко Олена', null, null, null, null ), $plan->source );
		self::assertSame( QuestionPlan::SINGLE_CHOICE, $plan->final_quiz->questions[0]->type );
		self::assertSame(
			[
				'modules'   => 0,
				'lessons'   => 2,
				'quizzes'   => 1,
				'questions' => 1,
				'images'    => 0,
			],
			$plan->counts()
		);
		self::assertSame( [], $plan->issues->codes() );
	}

	public function test_the_template_gives_its_issues_and_no_plan(): void {
		Functions\when( 'term_exists' )->justReturn( null );

		$result = $this->service()->analyse( self::FIXTURES . 'course-template.md' );

		self::assertNull( $result->plan );
		self::assertSame(
			[
				[ CourseValidator::NOT_ALLOWED, 3 ],
				[ CourseValidator::WRONG_TYPE, 12 ],
				[ CourseValidator::NEW_TERM, 13 ],
				[ CourseValidator::CLARIFY_MARKER, 25 ],
				[ CourseValidator::STRUCTURE_IGNORED, 35 ],
				[ CourseValidator::CLARIFY_MARKER, 61 ],
			],
			array_map( static fn ( ImportIssue $issue ): array => [ $issue->code, $issue->line ], $result->issues->all() )
		);
	}

	public function test_a_parse_error_stops_the_analysis_before_validation(): void {
		// A slug the validator rejects, and a lesson heading the parser rejects.
		$path = $this->temp_copy(
			'course-with-modules.md',
			[
				'slug: anesthesia-cesarean-basics' => 'slug: Bad_Slug',
				'## Урок 1.2. Премедикація'        => '## Урок премедикації',
			]
		);

		$result = $this->service()->analyse( $path );

		self::assertNull( $result->plan );
		self::assertSame( [ CourseDocumentParser::UNKNOWN_HEADING ], $result->issues->codes() );
		self::assertSame( 84, $result->issues->all()[0]->line );
	}

	public function test_a_validation_error_gives_no_plan(): void {
		$path = $this->temp_copy( 'course-with-modules.md', [ 'slug: anesthesia-cesarean-basics' => 'slug: Bad_Slug' ] );

		$result = $this->service()->analyse( $path );

		self::assertNull( $result->plan );
		self::assertTrue( $result->issues->has_errors() );
		self::assertContains( CourseValidator::NOT_ALLOWED, $result->issues->codes() );
	}

	public function test_an_unreadable_path_gives_one_error_and_no_plan(): void {
		$result = $this->service()->analyse( self::FIXTURES . 'no-such-course.md' );

		self::assertNull( $result->plan );
		self::assertSame( [ ImportService::UNREADABLE ], $result->issues->codes() );
		self::assertNull( $result->issues->all()[0]->line );
	}

	/**
	 * @dataProvider durations
	 */
	public function test_duration_is_rounded_to_half_an_hour( int $minutes, float $hours ): void {
		$path = $this->temp_copy( 'course-with-modules.md', [ 'duration_minutes: 90' => 'duration_minutes: ' . $minutes ] );

		$plan = $this->service()->analyse( $path )->plan;

		self::assertNotNull( $plan );
		self::assertSame( $hours, $plan->course->duration_hours );
	}

	/**
	 * @return array<string, array{int, float}>
	 */
	public static function durations(): array {
		return [
			'0 minutes'   => [ 0, 0.0 ],
			'14 minutes'  => [ 14, 0.0 ],
			'15 minutes'  => [ 15, 0.5 ],
			'45 minutes'  => [ 45, 1.0 ],
			'90 minutes'  => [ 90, 1.5 ],
			'100 minutes' => [ 100, 1.5 ],
		];
	}

	public function test_analysing_the_same_file_twice_gives_the_same_plan(): void {
		$service = $this->service();

		$first  = $service->analyse( self::FIXTURES . 'course-with-modules.md' )->plan;
		$second = $service->analyse( self::FIXTURES . 'course-with-modules.md' )->plan;

		self::assertNotNull( $first );
		self::assertSame( serialize( $first ), serialize( $second ) );
	}

	private function service( int $default_pass_percent = 70 ): ImportService {
		$markdown_to_html = new MarkdownToHtml();

		return new ImportService(
			new CourseDocumentParser( new FrontMatterParser(), new QuizBlockParser() ),
			new CourseValidator( $markdown_to_html ),
			new CourseHtmlBuilder( $markdown_to_html ),
			new ModuleHtmlBuilder(),
			new LessonHtmlBuilder( $markdown_to_html ),
			$default_pass_percent
		);
	}

	/**
	 * @param array<string, string> $replacements Each search string must occur exactly once.
	 */
	private function temp_copy( string $fixture, array $replacements ): string {
		$contents = (string) file_get_contents( self::FIXTURES . $fixture );
		foreach ( $replacements as $search => $replace ) {
			self::assertSame( 1, substr_count( $contents, $search ), "The fixture must hold «{$search}» exactly once." );
			$contents = str_replace( $search, $replace, $contents );
		}

		$path = (string) tempnam( sys_get_temp_dir(), 'vl-lms-import-' );
		file_put_contents( $path, $contents );
		$this->temp_files[] = $path;

		return $path;
	}
}
