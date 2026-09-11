<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Import\Validation;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Import\Convert\MarkdownToHtml;
use VL\LMS\Import\Issue\IssueList;
use VL\LMS\Import\Parser\CourseDocumentParser;
use VL\LMS\Import\Parser\FrontMatterParser;
use VL\LMS\Import\Parser\QuizBlockParser;
use VL\LMS\Import\Validation\CourseLevel;
use VL\LMS\Import\Validation\CourseValidator;

/**
 * Each case is a valid fixture with a few edits: a string key replaces its
 * single occurrence, an int key replaces that whole 1-based line — so the
 * expected line numbers can be read off the fixture.
 */
final class CourseValidatorTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const FIXTURES = __DIR__ . '/../../../Fixtures/Import/';

	private const MODULES = 'course-with-modules.md';
	private const FLAT    = 'course-flat.md';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'term_exists' )->justReturn(
			[
				'term_id'          => 7,
				'term_taxonomy_id' => 7,
			]
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_the_valid_fixtures_raise_only_the_structure_note_when_every_term_exists(): void {
		self::assertSame(
			[ [ CourseValidator::STRUCTURE_IGNORED, 37, 'info' ] ],
			$this->summary( $this->validate( $this->mutate( self::MODULES, [] ) ) )
		);
		self::assertSame( [], $this->summary( $this->validate( $this->mutate( self::FLAT, [] ) ) ) );
	}

	public function test_a_category_or_tag_the_site_lacks_is_a_warning(): void {
		$lookups = [];
		Functions\when( 'term_exists' )->alias(
			static function ( string $slug, string $taxonomy ) use ( &$lookups ): ?array {
				$lookups[] = [ $slug, $taxonomy ];

				return 'cesarean' === $slug ? null : [
					'term_id'          => 7,
					'term_taxonomy_id' => 7,
				];
			}
		);

		$issues = $this->validate( $this->mutate( self::MODULES, [] ) );

		self::assertSame( [ [ CourseValidator::NEW_TERM, 14, 'warning' ] ], $this->summary( $issues, [ CourseValidator::STRUCTURE_IGNORED ] ) );
		self::assertSame( [ [ 'anesthesia', 'vl_category' ], [ 'cesarean', 'vl_tag' ], [ 'anesthesia', 'vl_tag' ] ], $lookups );
	}

	public function test_the_template_fails_only_on_its_placeholders(): void {
		Functions\when( 'term_exists' )->justReturn( null );

		self::assertSame(
			[
				[ CourseValidator::NOT_ALLOWED, 3, 'error' ],
				[ CourseValidator::WRONG_TYPE, 12, 'error' ],
				[ CourseValidator::NEW_TERM, 13, 'warning' ],
				[ CourseValidator::CLARIFY_MARKER, 25, 'warning' ],
				[ CourseValidator::STRUCTURE_IGNORED, 35, 'info' ],
				[ CourseValidator::CLARIFY_MARKER, 61, 'warning' ],
			],
			$this->summary( $this->validate( $this->mutate( 'course-template.md', [] ) ) )
		);
	}

	public function test_the_parser_fixture_without_a_mandatory_section_is_reported(): void {
		self::assertSame(
			[ [ CourseValidator::MISSING_SECTION, 23, 'error' ] ],
			$this->summary( $this->validate( $this->mutate( 'broken/missing-section.md', [] ) ) )
		);
	}

	public function test_the_parser_fixture_without_a_correct_option_is_reported(): void {
		self::assertSame(
			[ [ CourseValidator::NO_CORRECT_OPTION, 41, 'error' ] ],
			$this->summary( $this->validate( $this->mutate( 'broken/question-without-correct.md', [] ) ) )
		);
	}

	/**
	 * @dataProvider broken_rules
	 *
	 * @param array<int|string, string>              $mutations
	 * @param list<array{string, int|null, string}> $expected
	 */
	public function test_reports_a_broken_rule_at_its_line( string $fixture, array $mutations, array $expected ): void {
		$issues = $this->validate( $this->mutate( $fixture, $mutations ) );

		self::assertSame( $expected, $this->summary( $issues, [ CourseValidator::STRUCTURE_IGNORED ] ) );
	}

	/**
	 * @return array<string, array{string, array<int|string, string>, list<array{string, int|null, string}>}>
	 */
	public static function broken_rules(): array {
		$literature = '- Mathews K. A., 2017. Analgesia and Anesthesia for the Ill or Injured Dog and Cat. Wiley.';

		return [
			'missing title'                        => [ self::MODULES, [ 2 => '' ], [ [ CourseValidator::MISSING_KEY, null, 'error' ] ] ],
			'missing slug'                         => [ self::MODULES, [ 3 => '' ], [ [ CourseValidator::MISSING_KEY, null, 'error' ] ] ],
			'missing author'                       => [ self::MODULES, [ 4 => '' ], [ [ CourseValidator::MISSING_KEY, null, 'error' ] ] ],
			'missing level'                        => [ self::MODULES, [ 6 => '' ], [ [ CourseValidator::MISSING_KEY, null, 'error' ] ] ],
			'missing language'                     => [ self::MODULES, [ 7 => '' ], [ [ CourseValidator::MISSING_KEY, null, 'error' ] ] ],
			'missing status'                       => [ self::MODULES, [ 8 => '' ], [ [ CourseValidator::MISSING_KEY, null, 'error' ] ] ],
			'missing version'                      => [ self::MODULES, [ 9 => '' ], [ [ CourseValidator::MISSING_KEY, null, 'error' ] ] ],
			'text key written as a number'         => [ self::MODULES, [ 2 => 'title: 2024' ], [ [ CourseValidator::WRONG_TYPE, 2, 'error' ] ] ],
			'number key written as text'           => [ self::MODULES, [ 9 => 'version: "1"' ], [ [ CourseValidator::WRONG_TYPE, 9, 'error' ] ] ],
			'date key written as text'             => [ self::MODULES, [ 12 => 'source_date: 20.11.2025' ], [ [ CourseValidator::WRONG_TYPE, 12, 'error' ] ] ],
			'list key written as text'             => [ self::MODULES, [ 14 => 'tags: cesarean' ], [ [ CourseValidator::WRONG_TYPE, 14, 'error' ] ] ],
			'level outside the list'               => [ self::MODULES, [ 6 => 'level: expert' ], [ [ CourseValidator::NOT_ALLOWED, 6, 'error' ] ] ],
			'language other than uk'               => [ self::MODULES, [ 7 => 'language: en' ], [ [ CourseValidator::NOT_ALLOWED, 7, 'error' ] ] ],
			'unknown status'                       => [ self::MODULES, [ 8 => 'status: published' ], [ [ CourseValidator::NOT_ALLOWED, 8, 'error' ] ] ],
			'unknown source type'                  => [ self::MODULES, [ 10 => 'source_type: podcast' ], [ [ CourseValidator::NOT_ALLOWED, 10, 'error' ] ] ],
			'slug with capitals and an underscore' => [ self::MODULES, [ 3 => 'slug: Anesthesia_Basics' ], [ [ CourseValidator::NOT_ALLOWED, 3, 'error' ] ] ],
			'slug with a trailing hyphen'          => [ self::MODULES, [ 3 => 'slug: anesthesia-' ], [ [ CourseValidator::NOT_ALLOWED, 3, 'error' ] ] ],
			'slug in cyrillic'                     => [ self::MODULES, [ 3 => 'slug: анестезія' ], [ [ CourseValidator::NOT_ALLOWED, 3, 'error' ] ] ],
			'negative duration'                    => [ self::MODULES, [ 15 => 'duration_minutes: -1' ], [ [ CourseValidator::NOT_ALLOWED, 15, 'error' ] ] ],
			'pass percent above 100'               => [ self::MODULES, [ 16 => 'quiz_pass_percent: 101' ], [ [ CourseValidator::NOT_ALLOWED, 16, 'error' ] ] ],
			'negative pass percent'                => [ self::MODULES, [ 16 => 'quiz_pass_percent: -1' ], [ [ CourseValidator::NOT_ALLOWED, 16, 'error' ] ] ],
			'unknown key'                          => [ self::MODULES, [ 11 => 'lecturer: "Іваненко Олена"' ], [ [ CourseValidator::UNKNOWN_KEY, 11, 'warning' ] ] ],
			'no course heading'                    => [ self::MODULES, [ 19 => '' ], [ [ CourseValidator::TITLE_HEADING_COUNT, null, 'error' ] ] ],
			'a second course heading'              => [ self::MODULES, [ 21 => '# Анестезія' ], [ [ CourseValidator::TITLE_HEADING_COUNT, 21, 'error' ] ] ],
			'course heading differs from title'    => [ self::MODULES, [ 19 => '# Анестезія при кесаревому розтині' ], [ [ CourseValidator::TITLE_MISMATCH, 19, 'warning' ] ] ],
			'module number gap'                    => [
				self::MODULES,
				[
					121 => '# Модуль 3. Ведення анестезії',
					123 => '## Урок 3.1. Моніторинг',
				],
				[ [ CourseValidator::MODULE_NUMBER, 121, 'error' ] ],
			],
			'module number repeat'                 => [
				self::MODULES,
				[
					121 => '# Модуль 1. Ведення анестезії',
					123 => '## Урок 1.1. Моніторинг',
				],
				[ [ CourseValidator::MODULE_NUMBER, 121, 'error' ] ],
			],
			'lesson number gap'                    => [ self::MODULES, [ 84 => '## Урок 1.3. Премедикація' ], [ [ CourseValidator::LESSON_NUMBER, 84, 'error' ] ] ],
			'lesson numbered for another module'   => [ self::MODULES, [ 84 => '## Урок 2.2. Премедикація' ], [ [ CourseValidator::LESSON_MODULE_NUMBER, 84, 'error' ] ] ],
			'lesson number gap without modules'    => [ self::FLAT, [ 42 => '## Урок 3. План знеболення' ], [ [ CourseValidator::LESSON_NUMBER, 42, 'error' ] ] ],
			'quiz numbered for another module'     => [ self::MODULES, [ 105 => '## Тест модуля 2' ], [ [ CourseValidator::QUIZ_MODULE_NUMBER, 105, 'error' ] ] ],
			'question number gap'                  => [ self::MODULES, [ 114 => '**3. Які зміни характерні для вагітності?**' ], [ [ CourseValidator::QUESTION_NUMBER, 114, 'error' ] ] ],
			'missing lesson section'               => [ self::MODULES, [ 98 => '' ], [ [ CourseValidator::MISSING_SECTION, 84, 'error' ] ] ],
			'empty lesson section'                 => [ self::MODULES, [ 132 => '' ], [ [ CourseValidator::MISSING_SECTION, 130, 'error' ] ] ],
			'missing module quiz'                  => [ self::MODULES, [ 105 => '' ], [ [ CourseValidator::MISSING_MODULE_QUIZ, 49, 'error' ] ] ],
			'missing final quiz'                   => [ self::MODULES, [ 141 => '' ], [ [ CourseValidator::MISSING_FINAL_QUIZ, null, 'error' ] ] ],
			'module quiz before a lesson'          => [
				self::MODULES,
				[
					121 => '',
					123 => '## Урок 1.3. Моніторинг',
				],
				[ [ CourseValidator::MISPLACED_QUIZ, 105, 'error' ] ],
			],
			'final quiz before a module'           => [
				self::MODULES,
				[
					105 => '## Підсумковий тест',
					141 => '## Тест модуля 2',
				],
				[
					[ CourseValidator::MISSING_MODULE_QUIZ, 49, 'error' ],
					[ CourseValidator::MISPLACED_QUIZ, 105, 'error' ],
				],
			],
			'module quiz without questions'        => [ self::MODULES, array_fill( 107, 11, '' ), [ [ CourseValidator::EMPTY_QUIZ, 105, 'error' ] ] ],
			'final quiz without questions'         => [ self::MODULES, array_fill( 145, 10, '' ), [ [ CourseValidator::EMPTY_QUIZ, 141, 'error' ] ] ],
			'question without a correct option'    => [ self::MODULES, [ 109 => '- [ ] Пропофол' ], [ [ CourseValidator::NO_CORRECT_OPTION, 107, 'error' ] ] ],
			'question with one option'             => [
				self::MODULES,
				[
					146 => '',
					148 => '',
				],
				[ [ CourseValidator::OPTION_COUNT, 145, 'error' ] ],
			],
			'question with seven options'          => [
				self::MODULES,
				[ '- [ ] Ксилазин' => "- [ ] Ксилазин\n- [ ] Медетомідин\n- [ ] Ацепромазин\n- [ ] Буторфанол" ],
				[ [ CourseValidator::OPTION_COUNT, 107, 'error' ] ],
			],
			'inline HTML in a lesson body'         => [ self::MODULES, [ 'мінімізувати депресію плода' => 'мінімізувати <b>депресію</b> плода' ], [ [ CourseValidator::RAW_HTML, 93, 'error' ] ] ],
			'an HTML block in a lesson body'       => [ self::MODULES, [ 132 => '<div class="note">Під час операції контролюють пульсоксиметрію.</div>' ], [ [ CourseValidator::RAW_HTML, 132, 'error' ] ] ],
			'inline HTML in «Про курс»'            => [ self::MODULES, [ 'Він для лікарів,' => 'Він <br> для лікарів,' ], [ [ CourseValidator::RAW_HTML, 27, 'error' ] ] ],
			'inline HTML in a quiz option'         => [ self::MODULES, [ 110 => '- [ ] <i>Діазепам</i>' ], [ [ CourseValidator::RAW_HTML, 110, 'error' ] ] ],
			'[УТОЧНИТИ] in a draft'                => [ self::MODULES, [ 'й артеріальний тиск.' => 'й артеріальний тиск **[УТОЧНИТИ]**.' ], [ [ CourseValidator::CLARIFY_MARKER, 132, 'warning' ] ] ],
			'[УТОЧНИТИ] in a quiz explanation'     => [ self::MODULES, [ '(Урок 1.2).' => '(Урок 1.2) [УТОЧНИТИ].' ], [ [ CourseValidator::CLARIFY_MARKER, 112, 'warning' ] ] ],
			'[УТОЧНИТИ] in a ready course'         => [
				self::MODULES,
				[
					8                      => 'status: ready',
					'й артеріальний тиск.' => 'й артеріальний тиск **[УТОЧНИТИ]**.',
				],
				[ [ CourseValidator::CLARIFY_MARKER, 132, 'error' ] ],
			],
			'open items left in a ready course'    => [
				self::MODULES,
				[
					8           => 'status: ready',
					158         => '## Позиції [УТОЧНИТИ]',
					$literature => '1. Уточнити дозу пропофолу (Урок 1.2).',
				],
				[ [ CourseValidator::OPEN_ITEMS_NOT_EMPTY, 158, 'error' ] ],
			],
		];
	}

	/**
	 * @dataProvider allowed_variants
	 *
	 * @param array<int|string, string> $mutations
	 */
	public function test_accepts_what_the_format_allows( string $fixture, array $mutations, string $absent_code ): void {
		$issues = $this->validate( $this->mutate( $fixture, $mutations ) );

		self::assertNotContains( $absent_code, $issues->codes() );
		self::assertFalse( $issues->has_errors() );
	}

	/**
	 * @return array<string, array{string, array<int|string, string>, string}>
	 */
	public static function allowed_variants(): array {
		$literature = '- Mathews K. A., 2017. Analgesia and Anesthesia for the Ill or Injured Dog and Cat. Wiley.';

		return [
			'two options'                                  => [ self::MODULES, [ 153 => '' ], CourseValidator::OPTION_COUNT ],
			'six options'                                  => [ self::MODULES, [ '- [ ] Ксилазин' => "- [ ] Ксилазин\n- [ ] Медетомідин\n- [ ] Ацепромазин" ], CourseValidator::OPTION_COUNT ],
			'the last module without a quiz'               => [ self::MODULES, [], CourseValidator::MISSING_MODULE_QUIZ ],
			'the last module with its own quiz'            => [
				self::MODULES,
				[ '## Підсумковий тест' => "## Тест модуля 2\n\n**1. Що контролюють під час операції?**\n- [x] Капнографію\n- [ ] Колір шерсті\n\n## Підсумковий тест" ],
				CourseValidator::MISPLACED_QUIZ,
			],
			'a ready course with empty open items'         => [
				self::MODULES,
				[
					8           => 'status: ready',
					158         => '## Позиції [УТОЧНИТИ]',
					$literature => '',
				],
				CourseValidator::OPEN_ITEMS_NOT_EMPTY,
			],
			'[УТОЧНИТИ] only inside an HTML comment'       => [
				self::MODULES,
				[
					8  => 'status: ready',
					21 => '<!-- [УТОЧНИТИ] джерело цифр -->',
				],
				CourseValidator::CLARIFY_MARKER,
			],
			'[УТОЧНИТИ] only in the open items of a draft' => [
				self::MODULES,
				[
					158         => '## Позиції [УТОЧНИТИ]',
					$literature => '1. **[УТОЧНИТИ]** доза пропофолу (Урок 1.2).',
				],
				CourseValidator::CLARIFY_MARKER,
			],
			'pass percent 0'                               => [ self::MODULES, [ 16 => 'quiz_pass_percent: 0' ], CourseValidator::NOT_ALLOWED ],
			'pass percent 100'                             => [ self::MODULES, [ 16 => 'quiz_pass_percent: 100' ], CourseValidator::NOT_ALLOWED ],
			'zero duration'                                => [ self::MODULES, [ 15 => 'duration_minutes: 0' ], CourseValidator::NOT_ALLOWED ],
			'a placeholder in angle brackets'              => [ self::MODULES, [ 'Капнографія — найшвидший' => '<Капнографія> — найшвидший' ], CourseValidator::RAW_HTML ],
		];
	}

	public function test_each_course_level_maps_to_a_difficulty_term(): void {
		self::assertSame( 'basic', CourseLevel::BEGINNER->difficulty_slug() );
		self::assertSame( 'advanced', CourseLevel::PRACTITIONER->difficulty_slug() );
		self::assertSame( 'expert', CourseLevel::ADVANCED->difficulty_slug() );
		self::assertNull( CourseLevel::tryFrom( 'expert' ) );
	}

	/**
	 * @param array<int|string, string> $mutations Int keys replace that whole 1-based line; string keys replace their single occurrence.
	 */
	private function mutate( string $fixture, array $mutations ): string {
		$lines = explode( "\n", (string) file_get_contents( self::FIXTURES . $fixture ) );

		foreach ( $mutations as $target => $replacement ) {
			if ( is_int( $target ) ) {
				$lines[ $target - 1 ] = $replacement;
			}
		}

		$contents = implode( "\n", $lines );

		foreach ( $mutations as $target => $replacement ) {
			if ( is_string( $target ) ) {
				self::assertSame( 1, substr_count( $contents, $target ), "The fixture must hold «{$target}» exactly once." );
				$contents = str_replace( $target, $replacement, $contents );
			}
		}

		return $contents;
	}

	private function validate( string $contents ): IssueList {
		$document = ( new CourseDocumentParser( new FrontMatterParser(), new QuizBlockParser() ) )->parse( $contents );
		self::assertSame( [], $document->issues->codes(), 'The edited fixture must still parse without issues.' );

		( new CourseValidator( new MarkdownToHtml() ) )->validate( $document );

		return $document->issues;
	}

	/**
	 * @param list<string> $skip_codes
	 *
	 * @return list<array{string, int|null, string}>
	 */
	private function summary( IssueList $issues, array $skip_codes = [] ): array {
		$summary = [];
		foreach ( $issues->all() as $issue ) {
			if ( ! in_array( $issue->code, $skip_codes, true ) ) {
				$summary[] = [ $issue->code, $issue->line, $issue->level->value ];
			}
		}

		return $summary;
	}
}
