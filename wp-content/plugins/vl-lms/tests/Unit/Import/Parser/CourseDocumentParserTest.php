<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Import\Parser;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Import\Document\CourseDocument;
use VL\LMS\Import\Document\CourseVariant;
use VL\LMS\Import\Document\FrontMatterType;
use VL\LMS\Import\Document\LessonSpec;
use VL\LMS\Import\Document\QuestionSpec;
use VL\LMS\Import\Document\QuizKind;
use VL\LMS\Import\Document\SectionSpec;
use VL\LMS\Import\Issue\ImportIssue;
use VL\LMS\Import\Issue\IssueLevel;
use VL\LMS\Import\Parser\CourseDocumentParser;
use VL\LMS\Import\Parser\FrontMatterParser;
use VL\LMS\Import\Parser\QuizBlockParser;

final class CourseDocumentParserTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg( 1 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_parses_the_customer_template_into_the_expected_tree(): void {
		$document = $this->parse_fixture( 'course-template.md' );

		self::assertSame( [], $document->issues->codes() );
		self::assertCount( 15, $document->front_matter->entries );
		self::assertSame( CourseVariant::MODULES, $document->variant );
		self::assertSame(
			[
				[
					'text' => '<Назва курсу>',
					'line' => 19,
				],
			],
			$document->title_headings
		);
		self::assertSame( 23, $document->about?->heading_line );
		self::assertSame( 27, $document->goals?->heading_line );
		self::assertSame( 35, $document->structure?->heading_line );
		self::assertSame( [], $document->lessons );
		self::assertCount( 2, $document->modules );

		$module_one = $document->modules[0];
		self::assertSame( [ 1, '<Назва модуля>', 50 ], [ $module_one->number, $module_one->title, $module_one->line ] );
		self::assertSame( [ 52, 79 ], self::lesson_lines( $module_one->lessons ) );

		$lesson = $module_one->lessons[0];
		self::assertSame( [ 1, 1, '<Назва уроку>' ], [ $lesson->module_number, $lesson->number, $lesson->title ] );
		self::assertSame( 54, $lesson->objectives?->heading_line );
		self::assertSame( 71, $lesson->takeaways?->heading_line );
		self::assertNull( $lesson->materials );
		self::assertInstanceOf( SectionSpec::class, $lesson->content );
		self::assertSame( 'Зміст', $lesson->content->heading );
		self::assertSame( 59, $lesson->content->heading_line );
		self::assertSame( 61, $lesson->content->body_line );
		self::assertStringStartsWith( '**<Підтема 1.>**', $lesson->content->body );
		self::assertStringEndsWith( '- <…>', $lesson->content->body );

		self::assertNotNull( $module_one->quiz );
		self::assertSame( QuizKind::MODULE, $module_one->quiz->kind );
		self::assertSame( 1, $module_one->quiz->module_number );
		self::assertSame( 95, $module_one->quiz->line );
		self::assertSame( [ 99, 106 ], self::question_lines( $module_one->quiz->questions ) );
		self::assertSame( [ false, true, false, false ], array_column( $module_one->quiz->questions[0]->options, 'correct' ) );
		self::assertSame( "<необов'язково — чому саме так, з посиланням на урок>", $module_one->quiz->questions[0]->explanation );
		self::assertNull( $module_one->quiz->questions[1]->explanation );

		$module_two = $document->modules[1];
		self::assertSame( [ 2, 113 ], [ $module_two->number, $module_two->line ] );
		self::assertSame( [ 115 ], self::lesson_lines( $module_two->lessons ) );
		self::assertSame( [ 2, 131 ], [ $module_two->quiz?->module_number, $module_two->quiz?->line ] );
		self::assertSame( [ 133 ], self::question_lines( $module_two->quiz?->questions ?? [] ) );

		self::assertNotNull( $document->final_quiz );
		self::assertSame( QuizKind::FINAL, $document->final_quiz->kind );
		self::assertNull( $document->final_quiz->module_number );
		self::assertSame( 140, $document->final_quiz->line );
		self::assertSame( [ 144, 150 ], self::question_lines( $document->final_quiz->questions ) );

		self::assertSame( 157, $document->literature?->heading_line );
		self::assertSame( 163, $document->open_items?->heading_line );
		self::assertSame( '1. <Що саме уточнити, у якому уроці.>', $document->open_items?->body );
		self::assertSame( 167, $document->open_items?->body_line );
	}

	public function test_parses_the_filled_course_with_modules(): void {
		$document = $this->parse_fixture( 'course-with-modules.md' );

		self::assertSame( [], $document->issues->codes() );
		self::assertSame( CourseVariant::MODULES, $document->variant );
		self::assertSame( [ 'cesarean', 'anesthesia' ], $document->front_matter->get( 'tags' )?->value );
		self::assertSame( FrontMatterType::DATE, $document->front_matter->get( 'source_date' )?->type );
		self::assertSame( 37, $document->structure?->heading_line );
		self::assertCount( 2, $document->modules );

		$module_one = $document->modules[0];
		self::assertSame( [ 1, 'Підготовка до анестезії', 49 ], [ $module_one->number, $module_one->title, $module_one->line ] );
		self::assertSame(
			[ 'Оцінка пацієнтки', 'Премедикація' ],
			array_map( static fn ( LessonSpec $lesson ): string => $lesson->title, $module_one->lessons )
		);

		$lesson = $module_one->lessons[0];
		self::assertSame( 60, $lesson->content?->body_line );
		foreach ( [ '#### Клас ASA', '| II | Легке системне захворювання |', '> Породілля завжди має щонайменше клас ASA II.', '![Монітор під час операції](assets/anesthesia-cesarean-basics/monitor.png)' ] as $markdown ) {
			self::assertStringContainsString( $markdown, $lesson->content->body ?? '' );
		}
		self::assertSame( 78, $lesson->materials?->heading_line );
		self::assertSame( '- [Рекомендації WSAVA з анестезії](https://wsava.org/global-guidelines/)', $lesson->materials?->body );
		self::assertNull( $module_one->lessons[1]->materials );

		$questions = $module_one->quiz?->questions ?? [];
		self::assertSame( [ 107, 114 ], self::question_lines( $questions ) );
		self::assertFalse( $questions[0]->is_multiple_choice() );
		self::assertSame( 'пропофол забезпечує швидку індукцію і пробудження (Урок 1.2).', $questions[0]->explanation );
		self::assertTrue( $questions[1]->is_multiple_choice() );

		$module_two = $document->modules[1];
		self::assertSame( [ 2, 121 ], [ $module_two->number, $module_two->line ] );
		self::assertSame( [ 123 ], self::lesson_lines( $module_two->lessons ) );
		self::assertNull( $module_two->quiz );

		self::assertSame( [ 145, 150 ], self::question_lines( $document->final_quiz?->questions ?? [] ) );
		self::assertSame( 158, $document->literature?->heading_line );
		self::assertNull( $document->open_items );
	}

	public function test_parses_a_course_without_modules(): void {
		$document = $this->parse_fixture( 'course-flat.md' );

		self::assertSame( [], $document->issues->codes() );
		self::assertSame( CourseVariant::FLAT, $document->variant );
		self::assertSame( [], $document->modules );
		self::assertSame( [ 24, 42 ], self::lesson_lines( $document->lessons ) );
		foreach ( $document->lessons as $index => $lesson ) {
			self::assertNull( $lesson->module_number );
			self::assertSame( $index + 1, $lesson->number );
			self::assertNotNull( $lesson->objectives );
			self::assertNotNull( $lesson->content );
			self::assertNotNull( $lesson->takeaways );
		}
		self::assertSame( 'План знеболення', $document->lessons[1]->title );
		self::assertSame( 60, $document->final_quiz?->line );
		self::assertSame( [ 62 ], self::question_lines( $document->final_quiz?->questions ?? [] ) );
	}

	/**
	 * @dataProvider broken_fixtures
	 *
	 * @param list<array{string, int}> $expected Issue codes with their file lines, in file order.
	 */
	public function test_a_broken_fixture_reports_exactly_its_issues( string $fixture, array $expected ): void {
		$document = $this->parse_fixture( $fixture );

		self::assertSame( $expected, self::issue_positions( $document ) );
		foreach ( $document->issues->all() as $issue ) {
			self::assertSame( IssueLevel::ERROR, $issue->level );
		}
	}

	/**
	 * @return array<string, array{string, list<array{string, int}>}>
	 */
	public static function broken_fixtures(): array {
		return [
			'unknown heading at each level'     => [
				'broken/unknown-heading.md',
				[ [ CourseDocumentParser::UNKNOWN_HEADING, 39 ], [ CourseDocumentParser::UNKNOWN_HEADING, 43 ], [ CourseDocumentParser::UNKNOWN_HEADING, 53 ] ],
			],
			'mixed variants'                    => [
				'broken/mixed-variants.md',
				[ [ CourseDocumentParser::MIXED_VARIANTS, 39 ] ],
			],
			'bad frontmatter line'              => [
				'broken/frontmatter-bad-line.md',
				[ [ FrontMatterParser::INVALID_VALUE, 5 ], [ FrontMatterParser::INVALID_LINE, 6 ] ],
			],
			'missing frontmatter'               => [
				'broken/frontmatter-missing.md',
				[ [ CourseDocumentParser::FRONTMATTER_MISSING, 1 ] ],
			],
			'unclosed frontmatter'              => [
				'broken/frontmatter-unclosed.md',
				[ [ CourseDocumentParser::FRONTMATTER_UNCLOSED, 1 ] ],
			],
			'misplaced headings'                => [
				'broken/misplaced-heading.md',
				[ [ CourseDocumentParser::MISPLACED_HEADING, 21 ], [ CourseDocumentParser::MISPLACED_HEADING, 25 ], [ CourseDocumentParser::MISPLACED_HEADING, 31 ] ],
			],
			'duplicate sections'                => [
				'broken/duplicate-section.md',
				[ [ CourseDocumentParser::DUPLICATE_SECTION, 21 ], [ CourseDocumentParser::DUPLICATE_SECTION, 38 ], [ CourseDocumentParser::DUPLICATE_SECTION, 52 ], [ CourseDocumentParser::DUPLICATE_SECTION, 63 ] ],
			],
			'orphan content'                    => [
				'broken/orphan-content.md',
				[ [ CourseDocumentParser::ORPHAN_CONTENT, 11 ], [ CourseDocumentParser::ORPHAN_CONTENT, 15 ], [ CourseDocumentParser::ORPHAN_CONTENT, 27 ], [ CourseDocumentParser::ORPHAN_CONTENT, 32 ] ],
			],
			'unexpected quiz lines'             => [
				'broken/quiz-unexpected-line.md',
				[ [ QuizBlockParser::UNEXPECTED_LINE, 41 ], [ QuizBlockParser::UNEXPECTED_LINE, 48 ] ],
			],
			'unclosed comment'                  => [
				'broken/unclosed-comment.md',
				[ [ CourseDocumentParser::UNCLOSED_COMMENT, 34 ] ],
			],
			// The next two break validator rules (Step 3), not parser rules.
			'missing section'                   => [
				'broken/missing-section.md',
				[],
			],
			'question without a correct option' => [
				'broken/question-without-correct.md',
				[],
			],
		];
	}

	public function test_a_missing_section_parses_to_a_lesson_without_it(): void {
		$lesson = $this->parse_fixture( 'broken/missing-section.md' )->modules[0]->lessons[0];

		self::assertNotNull( $lesson->objectives );
		self::assertNotNull( $lesson->content );
		self::assertNull( $lesson->takeaways );
	}

	public function test_a_question_without_a_correct_option_parses_with_every_option_incorrect(): void {
		$questions = $this->parse_fixture( 'broken/question-without-correct.md' )->final_quiz?->questions ?? [];

		self::assertCount( 1, $questions );
		self::assertSame( [ false, false ], array_column( $questions[0]->options, 'correct' ) );
	}

	public function test_rejected_headings_leave_the_rest_of_the_tree_intact(): void {
		$unknown = $this->parse_fixture( 'broken/unknown-heading.md' );
		self::assertSame( 'Зміст уроку.', $unknown->modules[0]->lessons[0]->content?->body );
		self::assertNotNull( $unknown->modules[0]->lessons[0]->takeaways );
		self::assertSame( 47, $unknown->final_quiz?->line );

		$mixed = $this->parse_fixture( 'broken/mixed-variants.md' );
		self::assertSame( CourseVariant::MODULES, $mixed->variant );
		self::assertSame( [ 23 ], self::lesson_lines( $mixed->modules[0]->lessons ) );
		self::assertSame( [], $mixed->lessons );

		$misplaced = $this->parse_fixture( 'broken/misplaced-heading.md' );
		self::assertSame( '- Знати основи.', $misplaced->goals?->body );
		self::assertCount( 1, $misplaced->modules );
		self::assertSame( [ 38 ], self::lesson_lines( $misplaced->modules[0]->lessons ) );
		self::assertNull( $misplaced->modules[0]->quiz );

		$duplicate = $this->parse_fixture( 'broken/duplicate-section.md' );
		self::assertSame( 'Короткий опис курсу.', $duplicate->about?->body );
		self::assertSame( 'Зміст уроку.', $duplicate->modules[0]->lessons[0]->content?->body );
		self::assertSame( 'Питання модуля?', $duplicate->modules[0]->quiz?->questions[0]->text );
		self::assertSame( 'Питання?', $duplicate->final_quiz?->questions[0]->text );

		$orphan = $this->parse_fixture( 'broken/orphan-content.md' );
		self::assertSame( 13, $orphan->title_headings[0]['line'] );
		self::assertNotNull( $orphan->modules[0]->lessons[0]->objectives );

		$quiz = $this->parse_fixture( 'broken/quiz-unexpected-line.md' );
		self::assertCount( 2, $quiz->final_quiz?->questions ?? [] );
		self::assertCount( 1, $quiz->final_quiz?->questions[1]->options ?? [] );
	}

	public function test_frontmatter_problems_keep_or_drop_the_body(): void {
		$missing = $this->parse_fixture( 'broken/frontmatter-missing.md' );
		self::assertSame( [], $missing->front_matter->entries );
		self::assertSame( 1, $missing->title_headings[0]['line'] );
		self::assertSame( 11, $missing->modules[0]->line );

		$unclosed = $this->parse_fixture( 'broken/frontmatter-unclosed.md' );
		self::assertSame( [], $unclosed->front_matter->entries );
		self::assertSame( [], $unclosed->title_headings );
		self::assertNull( $unclosed->variant );
		self::assertNull( $unclosed->about );
		self::assertSame( [], $unclosed->modules );
	}

	public function test_an_unclosed_comment_swallows_the_rest_of_the_file(): void {
		$document = $this->parse_fixture( 'broken/unclosed-comment.md' );
		$lesson   = $document->modules[0]->lessons[0];

		self::assertSame( 'Зміст уроку.', $lesson->content?->body );
		self::assertSame( 32, $lesson->content?->body_line );
		self::assertNull( $lesson->takeaways );
		self::assertNull( $document->final_quiz );
	}

	public function test_a_broken_numbered_prefix_is_never_a_title_and_silences_its_children(): void {
		$document = $this->parser()->parse( "---\ntitle: \"Курс\"\n---\n# Курс\n\n# Модуль 1 Вступ\n\n## Урок 1.1. Урок\n\n### Зміст\n\nТекст.\n" );

		self::assertSame( [ [ CourseDocumentParser::UNKNOWN_HEADING, 6 ] ], self::issue_positions( $document ) );
		self::assertSame( [ 'Курс' ], array_column( $document->title_headings, 'text' ) );
		self::assertSame( [], $document->modules );
	}

	public function test_headings_follow_the_atx_rules_for_levels_one_to_three(): void {
		$document = $this->parser()->parse( "---\ntitle: \"Курс\"\n---\n# Курс\n\n## Урок 1. Урок\n\n   ### Зміст\n\n#Хештег у тексті.\n\n#### Підзаголовок\n" );

		self::assertSame( [], $document->issues->codes() );
		self::assertSame( "#Хештег у тексті.\n\n#### Підзаголовок", $document->lessons[0]->content?->body );
		self::assertSame( 10, $document->lessons[0]->content?->body_line );
	}

	public function test_a_comment_inside_a_line_is_blanked_and_line_numbers_stay_true(): void {
		$document = $this->parser()->parse( "---\ntitle: \"Курс\"\n---\n# Курс\n\n## Про курс\n\nПочаток <!-- коментар\nу два рядки --> кінець.\nДругий рядок.\n" );

		self::assertSame( [], $document->issues->codes() );
		self::assertSame( "Початок \n кінець.\nДругий рядок.", $document->about?->body );
		self::assertSame( 8, $document->about?->body_line );
	}

	public function test_an_empty_section_has_an_empty_body_on_the_line_after_its_heading(): void {
		$document = $this->parser()->parse( "---\ntitle: \"Курс\"\n---\n# Курс\n\n## Про курс\n\n## Мета курсу\n" );

		self::assertSame( '', $document->about?->body );
		self::assertSame( 7, $document->about?->body_line );
	}

	public function test_a_file_with_only_frontmatter_gives_an_empty_tree(): void {
		$document = $this->parser()->parse( "---\ntitle: \"Курс\"\n---\n" );

		self::assertSame( [], $document->issues->codes() );
		self::assertCount( 1, $document->front_matter->entries );
		self::assertNull( $document->variant );
		self::assertSame( [], $document->title_headings );
		self::assertNull( $document->final_quiz );
	}

	/**
	 * The step's round-trip property: every non-blank line of the body that is
	 * not a structural heading, a `---` rule or a comment lands in exactly one
	 * section body, in order, on its own file line. The oracle is deliberately
	 * simpler than the parser (one regex for comments, one for headings).
	 *
	 * @dataProvider valid_fixtures
	 */
	public function test_section_bodies_reproduce_the_input_without_headings_rules_and_comments( string $fixture ): void {
		$contents   = $this->fixture( $fixture );
		$document   = $this->parser()->parse( $contents );
		$file_lines = explode( "\n", $contents );

		self::assertSame( [], $document->issues->codes() );

		$closing  = (int) array_search( '---', array_slice( $file_lines, 1 ), true ) + 1;
		$body     = (string) preg_replace( '/<!--.*?-->/s', '', implode( "\n", array_slice( $file_lines, $closing + 1 ) ) );
		$expected = [];
		foreach ( explode( "\n", $body ) as $line ) {
			$line = rtrim( $line );
			if ( '' !== $line && '---' !== $line && 1 !== preg_match( '/^ {0,3}#{1,3}(\s|$)/u', $line ) ) {
				$expected[] = $line;
			}
		}

		$sections = self::sections( $document );
		usort( $sections, static fn ( SectionSpec $a, SectionSpec $b ): int => $a->body_line <=> $b->body_line );

		$actual = [];
		foreach ( $sections as $section ) {
			foreach ( explode( "\n", $section->body ) as $offset => $line ) {
				if ( '' === rtrim( $line ) ) {
					continue;
				}
				$actual[] = rtrim( $line );
				self::assertSame( rtrim( $file_lines[ $section->body_line - 1 + $offset ] ), rtrim( $line ), "Line {$section->body_line}+{$offset} of «{$section->heading}»" );
			}
		}

		self::assertSame( $expected, $actual );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function valid_fixtures(): array {
		return [
			'customer template'      => [ 'course-template.md' ],
			'course with modules'    => [ 'course-with-modules.md' ],
			'course without modules' => [ 'course-flat.md' ],
		];
	}

	public function test_a_bom_and_other_line_endings_parse_like_the_lf_file(): void {
		$lf = $this->fixture( 'course-flat.md' );

		self::assertEquals( $this->parser()->parse( $lf ), $this->parser()->parse( "\u{FEFF}" . str_replace( "\n", "\r\n", $lf ) ) );
		self::assertEquals( $this->parser()->parse( $lf ), $this->parser()->parse( str_replace( "\n", "\r", $lf ) ) );
	}

	public function test_invalid_utf8_stops_parsing_with_one_issue_at_its_line(): void {
		$document = $this->parser()->parse( "---\ntitle: \"Курс\"\n---\n\n# Курс\n\n## Про курс\n\nТекст \xFF\xFE\n" );

		self::assertSame( [ [ CourseDocumentParser::INVALID_ENCODING, 9 ] ], self::issue_positions( $document ) );
		self::assertSame( [], $document->front_matter->entries );
		self::assertNull( $document->about );
	}

	private function parser(): CourseDocumentParser {
		return new CourseDocumentParser( new FrontMatterParser(), new QuizBlockParser() );
	}

	private function parse_fixture( string $name ): CourseDocument {
		return $this->parser()->parse( $this->fixture( $name ) );
	}

	private function fixture( string $name ): string {
		return (string) file_get_contents( dirname( __DIR__, 3 ) . '/Fixtures/Import/' . $name );
	}

	/**
	 * @return list<array{string, int|null}>
	 */
	private static function issue_positions( CourseDocument $document ): array {
		return array_map( static fn ( ImportIssue $issue ): array => [ $issue->code, $issue->line ], $document->issues->all() );
	}

	/**
	 * @param list<LessonSpec> $lessons
	 *
	 * @return list<int>
	 */
	private static function lesson_lines( array $lessons ): array {
		return array_map( static fn ( LessonSpec $lesson ): int => $lesson->line, $lessons );
	}

	/**
	 * @param list<QuestionSpec> $questions
	 *
	 * @return list<int>
	 */
	private static function question_lines( array $questions ): array {
		return array_map( static fn ( QuestionSpec $question ): int => $question->line, $questions );
	}

	/**
	 * Every section of the tree, quiz sections included.
	 *
	 * @return list<SectionSpec>
	 */
	private static function sections( CourseDocument $document ): array {
		$sections = [ $document->about, $document->goals, $document->structure, $document->literature, $document->open_items, $document->final_quiz?->section ];
		$lessons  = $document->lessons;
		foreach ( $document->modules as $module ) {
			$sections[] = $module->quiz?->section;
			$lessons    = array_merge( $lessons, $module->lessons );
		}
		foreach ( $lessons as $lesson ) {
			array_push( $sections, $lesson->objectives, $lesson->content, $lesson->takeaways, $lesson->materials );
		}

		return array_values( array_filter( $sections ) );
	}
}
