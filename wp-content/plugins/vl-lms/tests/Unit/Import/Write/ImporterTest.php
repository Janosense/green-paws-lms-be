<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Import\Write;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use VL\LMS\Import\Convert\CourseHtmlBuilder;
use VL\LMS\Import\Convert\LessonHtmlBuilder;
use VL\LMS\Import\Convert\MarkdownToHtml;
use VL\LMS\Import\Convert\ModuleHtmlBuilder;
use VL\LMS\Import\ImportService;
use VL\LMS\Import\Parser\CourseDocumentParser;
use VL\LMS\Import\Parser\FrontMatterParser;
use VL\LMS\Import\Parser\QuizBlockParser;
use VL\LMS\Import\Plan\ImportPlan;
use VL\LMS\Import\Validation\CourseValidator;
use VL\LMS\Import\Write\ImportContext;
use VL\LMS\Import\Write\Importer;
use VL\LMS\Import\Write\ImportResult;
use WP_Error;

/**
 * WordPress writes are recorded, not performed: `wp_insert_post` hands out
 * ids from 101 in call order, so the expected parents can be read off the
 * insert sequence.
 */
final class ImporterTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const FIXTURES = __DIR__ . '/../../../Fixtures/Import/';

	private const TOKEN         = '0123456789abcdef0123456789abcdef';
	private const INSTRUCTOR_ID = 5;
	private const ADMIN_ID      = 1;
	private const NOW           = 1789120000;

	/**
	 * @var list<array<string, mixed>> `wp_insert_post` arguments as received (slashed).
	 */
	private array $raw_inserts = [];

	/**
	 * @var list<array<string, mixed>> The same arguments, unslashed.
	 */
	private array $inserts = [];

	/**
	 * @var list<array{int, list<int>, string}>
	 */
	private array $term_sets = [];

	/**
	 * @var list<array{string, string, array<string, string>}>
	 */
	private array $term_inserts = [];

	/**
	 * @var list<int>
	 */
	private array $deleted = [];

	/**
	 * @var list<array{string, int, string, string, int}>
	 */
	private array $slug_checks = [];

	/**
	 * @var array<string, int|null> "taxonomy/slug" → term id, null for a missing term; unlisted terms exist with id 7.
	 */
	private array $terms = [];

	private ?string $unique_slug = null;

	private int $fail_insert_at = 0;

	private int $throw_insert_at = 0;

	private bool $fail_term_insert = false;

	private bool $fail_term_set = false;

	private int $undeletable = 0;

	/**
	 * @var list<string>
	 */
	private array $temp_files = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'esc_html' )->alias( static fn ( string $text ): string => htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ) );
		Functions\when( 'wp_kses_post' )->returnArg( 1 );
		Functions\when( 'wp_slash' )->alias( [ self::class, 'slash' ] );
		Functions\when( 'is_wp_error' )->alias( static fn ( mixed $thing ): bool => $thing instanceof WP_Error );
		Functions\when( 'time' )->justReturn( self::NOW );

		Functions\when( 'term_exists' )->alias(
			function ( string $slug, string $taxonomy ): ?array {
				$key = $taxonomy . '/' . $slug;
				$id  = array_key_exists( $key, $this->terms ) ? $this->terms[ $key ] : 7;

				return null === $id ? null : [
					'term_id'          => (string) $id,
					'term_taxonomy_id' => (string) $id,
				];
			}
		);
		Functions\when( 'wp_insert_term' )->alias(
			function ( string $name, string $taxonomy, array $args ): array|WP_Error {
				$this->term_inserts[] = [ $name, $taxonomy, $args ];
				if ( $this->fail_term_insert ) {
					return new WP_Error( 'db_insert_error', 'Could not insert term into the database.' );
				}
				$id = 20 + count( $this->term_inserts );

				return [
					'term_id'          => $id,
					'term_taxonomy_id' => $id,
				];
			}
		);
		Functions\when( 'wp_set_object_terms' )->alias(
			function ( int $object_id, array $terms, string $taxonomy ): array|WP_Error {
				$this->term_sets[] = [ $object_id, $terms, $taxonomy ];

				return $this->fail_term_set ? new WP_Error( 'invalid_taxonomy', 'Invalid taxonomy.' ) : $terms;
			}
		);
		Functions\when( 'wp_unique_post_slug' )->alias(
			function ( string $slug, int $post_id, string $status, string $post_type, int $post_parent ): string {
				$this->slug_checks[] = [ $slug, $post_id, $status, $post_type, $post_parent ];

				return $this->unique_slug ?? $slug;
			}
		);
		Functions\when( 'wp_insert_post' )->alias(
			function ( array $postarr ): int|WP_Error {
				$this->raw_inserts[] = $postarr;
				$this->inserts[]     = self::unslash( $postarr );
				$call                = count( $this->inserts );

				if ( $call === $this->throw_insert_at ) {
					throw new RuntimeException( 'boom' );
				}
				if ( $call === $this->fail_insert_at ) {
					return new WP_Error( 'db_insert_error', 'Could not insert post into the database.' );
				}

				return 100 + $call;
			}
		);
		Functions\when( 'wp_delete_post' )->alias(
			function ( int $id, bool $force ): mixed {
				self::assertTrue( $force );
				$this->deleted[] = $id;

				return $id === $this->undeletable ? false : Mockery::mock( 'WP_Post' );
			}
		);
	}

	protected function tearDown(): void {
		foreach ( $this->temp_files as $path ) {
			unlink( $path );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_creates_the_course_tree_parents_before_children(): void {
		$this->run_import( 'course-with-modules.md' );

		self::assertSame(
			[
				[ 'vl_course', null, null, 'Анестезія при кесаревому розтині: базовий курс' ],
				[ 'vl_module', 101, 1, 'Підготовка до анестезії' ],
				[ 'vl_lesson', 102, 1, 'Оцінка пацієнтки' ],
				[ 'vl_lesson', 102, 2, 'Премедикація' ],
				[ 'vl_quiz', 102, null, 'Тест модуля 1' ],
				[ 'vl_quiz_question', 105, 1, 'Який препарат найчастіше обирають для індукції?' ],
				[ 'vl_quiz_question', 105, 2, 'Які зміни характерні для вагітності?' ],
				[ 'vl_module', 101, 2, 'Ведення анестезії' ],
				[ 'vl_lesson', 108, 1, 'Моніторинг' ],
				[ 'vl_quiz', 101, null, 'Підсумковий тест' ],
				[ 'vl_quiz_question', 110, 1, 'Що є найшвидшим сигналом про порушення вентиляції?' ],
				[ 'vl_quiz_question', 110, 2, 'Який мінімальний клас ASA у породіллі?' ],
			],
			array_map(
				static fn ( array $insert ): array => [ $insert['post_type'], $insert['post_parent'] ?? null, $insert['menu_order'] ?? null, $insert['post_title'] ],
				$this->inserts
			)
		);
	}

	public function test_every_post_is_a_draft_by_the_lead_instructor_marked_with_the_token(): void {
		$this->run_import( 'course-with-modules.md' );

		foreach ( $this->inserts as $insert ) {
			self::assertSame( 'draft', $insert['post_status'] );
			self::assertSame( self::INSTRUCTOR_ID, $insert['post_author'] );
			self::assertSame( '_vl_import_id', array_key_first( $insert['meta_input'] ) );
			self::assertSame( self::TOKEN, $insert['meta_input']['_vl_import_id'] );
		}
	}

	public function test_the_course_gets_its_slug_html_and_meta(): void {
		$this->run_import( 'course-with-modules.md' );
		$course = $this->inserts[0];

		self::assertSame( 'anesthesia-cesarean-basics', $course['post_name'] );
		self::assertSame( file_get_contents( self::FIXTURES . 'convert/course.html' ), $course['post_content'] );
		self::assertSame(
			[
				'_vl_import_id'                => self::TOKEN,
				'_vl_course_type'              => 'self_paced',
				'_vl_course_completion_mode'   => 'free',
				'_vl_course_currency'          => 'UAH',
				'_vl_course_passing_threshold' => 70,
				'_vl_course_duration_hours'    => 1.5,
				'_vl_import_source'            => [
					'slug'         => 'anesthesia-cesarean-basics',
					'version'      => 1,
					'status'       => 'draft',
					'author'       => 'Іваненко Олена',
					'author_org'   => 'Ветеринарна клініка «Лапа»',
					'source_type'  => 'webinar',
					'source_title' => 'Кесарів розтин. Анестезіологічний супровід',
					'source_date'  => '2025-11-20',
					'imported_at'  => '2026-09-11T09:46:40Z',
					'imported_by'  => self::ADMIN_ID,
				],
			],
			$course['meta_input']
		);
		self::assertSame( file_get_contents( self::FIXTURES . 'convert/lesson.html' ), $this->inserts[2]['post_content'] );
		self::assertSame( "<p>Підготовка до анестезії</p>\n", $this->inserts[1]['post_content'] );
	}

	public function test_quizzes_and_questions_get_their_meta_and_only_the_final_quiz_is_the_final_exam(): void {
		$this->run_import( 'course-with-modules.md' );

		self::assertSame(
			[
				'_vl_import_id'              => self::TOKEN,
				'_vl_quiz_passing_threshold' => 70,
			],
			$this->inserts[4]['meta_input']
		);
		self::assertSame(
			[
				'_vl_import_id'              => self::TOKEN,
				'_vl_quiz_passing_threshold' => 70,
				'_vl_quiz_is_final_exam'     => true,
			],
			$this->inserts[9]['meta_input']
		);
		self::assertSame(
			[
				'_vl_import_id'            => self::TOKEN,
				'_vl_question_type'        => 'single_choice',
				'_vl_question_points'      => 1,
				'_vl_question_answers'     => [
					$this->answer( 'Кетамін', false ),
					$this->answer( 'Пропофол', true ),
					$this->answer( 'Діазепам', false ),
					$this->answer( 'Ксилазин', false ),
				],
				'_vl_question_explanation' => 'пропофол забезпечує швидку індукцію і пробудження (Урок 1.2).',
			],
			$this->inserts[5]['meta_input']
		);
		self::assertSame(
			[
				'_vl_import_id'        => self::TOKEN,
				'_vl_question_type'    => 'multiple_choice',
				'_vl_question_points'  => 1,
				'_vl_question_answers' => [
					$this->answer( "Зростання хвилинного об'єму серця", true ),
					$this->answer( 'Збільшення функціональної залишкової ємності легень', false ),
					$this->answer( 'Зменшення функціональної залишкової ємності легень', true ),
				],
			],
			$this->inserts[6]['meta_input']
		);

		foreach ( $this->inserts as $index => $insert ) {
			self::assertArrayNotHasKey( '_vl_quiz_blocks_progression', $insert['meta_input'] );
			self::assertArrayNotHasKey( '_vl_quiz_requires_all_quizzes_passed', $insert['meta_input'] );
			self::assertSame( 9 === $index, isset( $insert['meta_input']['_vl_quiz_is_final_exam'] ) );
			self::assertArrayNotHasKey( '_vl_course_certificate_enabled', $insert['meta_input'] );
			self::assertArrayNotHasKey( '_vl_course_price', $insert['meta_input'] );
		}
	}

	public function test_terms_are_assigned_by_id_and_missing_categories_and_tags_are_created(): void {
		$this->terms = [
			'vl_difficulty/advanced' => 3,
			'vl_category/anesthesia' => null,
			'vl_tag/cesarean'        => 8,
			'vl_tag/anesthesia'      => null,
		];

		$this->run_import( 'course-with-modules.md' );

		self::assertSame(
			[
				[ 'anesthesia', 'vl_category', [ 'slug' => 'anesthesia' ] ],
				[ 'anesthesia', 'vl_tag', [ 'slug' => 'anesthesia' ] ],
			],
			$this->term_inserts
		);
		self::assertSame(
			[
				[ 101, [ 3 ], 'vl_difficulty' ],
				[ 101, [ 21 ], 'vl_category' ],
				[ 101, [ 8, 22 ], 'vl_tag' ],
			],
			$this->term_sets
		);
	}

	public function test_a_missing_difficulty_term_is_a_warning_and_the_course_gets_none(): void {
		$this->terms = [ 'vl_difficulty/advanced' => null ];

		$result = $this->run_import( 'course-with-modules.md' );

		self::assertTrue( $result->created );
		self::assertContains( Importer::DIFFICULTY_MISSING, $result->issues->codes() );
		self::assertSame( [ 'vl_category', 'vl_tag' ], array_column( $this->term_sets, 2 ) );
	}

	public function test_the_result_lists_every_created_post_and_keeps_the_plan_issues(): void {
		$result = $this->run_import( 'course-with-modules.md' );

		self::assertTrue( $result->created );
		self::assertSame( 101, $result->course_id );
		self::assertSame( range( 101, 112 ), array_column( $result->entities, 'id' ) );
		self::assertSame( array_column( $this->inserts, 'post_type' ), array_column( $result->entities, 'type' ) );
		self::assertSame( array_column( $this->inserts, 'post_title' ), array_column( $result->entities, 'title' ) );
		self::assertSame( [ CourseValidator::STRUCTURE_IGNORED ], $result->issues->codes() );
		self::assertSame( [], $this->deleted );
	}

	public function test_a_course_without_modules_hangs_lessons_and_the_final_exam_on_the_course(): void {
		$result = $this->run_import( 'course-flat.md' );

		self::assertTrue( $result->created );
		self::assertSame(
			[
				[ 'vl_course', null, null ],
				[ 'vl_lesson', 101, 1 ],
				[ 'vl_lesson', 101, 2 ],
				[ 'vl_quiz', 101, null ],
				[ 'vl_quiz_question', 104, 1 ],
			],
			array_map(
				static fn ( array $insert ): array => [ $insert['post_type'], $insert['post_parent'] ?? null, $insert['menu_order'] ?? null ],
				$this->inserts
			)
		);
		self::assertTrue( $this->inserts[3]['meta_input']['_vl_quiz_is_final_exam'] );
		self::assertSame( [], $this->term_inserts );
		self::assertSame( [ [ 101, [ 7 ], 'vl_difficulty' ] ], $this->term_sets );
	}

	public function test_a_taken_slug_gets_the_suffix_wordpress_would_give_and_a_warning(): void {
		$this->unique_slug = 'anesthesia-cesarean-basics-2';

		$result = $this->run_import( 'course-with-modules.md' );

		self::assertSame( [ [ 'anesthesia-cesarean-basics', 0, 'publish', 'vl_course', 0 ] ], $this->slug_checks );
		self::assertSame( 'anesthesia-cesarean-basics-2', $this->inserts[0]['post_name'] );
		self::assertContains( Importer::SLUG_CHANGED, $result->issues->codes() );
	}

	public function test_a_free_slug_raises_no_warning(): void {
		$result = $this->run_import( 'course-with-modules.md' );

		self::assertCount( 1, $this->slug_checks );
		self::assertNotContains( Importer::SLUG_CHANGED, $result->issues->codes() );
	}

	/**
	 * @dataProvider failing_inserts
	 *
	 * @param list<int> $deleted
	 */
	public function test_a_failed_insert_rolls_back_everything_created_before_it( int $failing_insert, array $deleted ): void {
		$this->fail_insert_at = $failing_insert;

		$result = $this->run_import( 'course-with-modules.md' );

		self::assertFalse( $result->created );
		self::assertSame( 'Could not insert post into the database.', $result->reason );
		self::assertSame( $deleted, $this->deleted );
		self::assertCount( $failing_insert, $this->inserts );
		self::assertSame( [], $result->leftovers );
	}

	/**
	 * @return array<string, array{int, list<int>}>
	 */
	public static function failing_inserts(): array {
		return [
			'the course'        => [ 1, [] ],
			'the module quiz'   => [ 5, [ 104, 103, 102, 101 ] ],
			'the last question' => [ 12, [ 111, 110, 109, 108, 107, 106, 105, 104, 103, 102, 101 ] ],
		];
	}

	public function test_a_failed_term_insert_rolls_back_the_course(): void {
		$this->terms            = [ 'vl_category/anesthesia' => null ];
		$this->fail_term_insert = true;

		$result = $this->run_import( 'course-with-modules.md' );

		self::assertFalse( $result->created );
		self::assertSame( 'Could not insert term into the database.', $result->reason );
		self::assertSame( [ 101 ], $this->deleted );
		self::assertCount( 1, $this->inserts );
	}

	public function test_a_failed_term_assignment_rolls_back_the_course(): void {
		$this->fail_term_set = true;

		$result = $this->run_import( 'course-with-modules.md' );

		self::assertFalse( $result->created );
		self::assertSame( 'Invalid taxonomy.', $result->reason );
		self::assertSame( [ 101 ], $this->deleted );
	}

	public function test_an_exception_during_the_run_rolls_back(): void {
		$this->throw_insert_at = 3;

		$result = $this->run_import( 'course-with-modules.md' );

		self::assertFalse( $result->created );
		self::assertSame( 'boom', $result->reason );
		self::assertSame( [ 102, 101 ], $this->deleted );
	}

	public function test_posts_the_rollback_could_not_delete_are_reported(): void {
		$this->fail_insert_at = 3;
		$this->undeletable    = 101;

		$result = $this->run_import( 'course-with-modules.md' );

		self::assertFalse( $result->created );
		self::assertSame( [ 102, 101 ], $this->deleted );
		self::assertSame( [ 101 ], $result->leftovers );
	}

	public function test_texts_reach_wordpress_slashed(): void {
		$path = $this->temp_copy(
			'course-with-modules.md',
			[
				'Премедикацію обирають так' => 'Премедикацію (див. C:\temp) обирають так',
				'- [ ] Діазепам'            => '- [ ] Діазепам\Мідазолам',
			]
		);

		$this->run_import( $path );

		self::assertStringContainsString( 'C:\\\\temp', $this->raw_inserts[3]['post_content'] );
		self::assertSame( 'Діазепам\\\\Мідазолам', $this->raw_inserts[5]['meta_input']['_vl_question_answers'][2]['text'] );
		self::assertStringContainsString( 'C:\\temp', $this->inserts[3]['post_content'] );
	}

	/**
	 * `wp_slash()` as WordPress implements it: `addslashes()` on every string, recursively.
	 */
	public static function slash( mixed $value ): mixed {
		if ( is_array( $value ) ) {
			return array_map( [ self::class, 'slash' ], $value );
		}

		return is_string( $value ) ? addslashes( $value ) : $value;
	}

	private static function unslash( mixed $value ): mixed {
		if ( is_array( $value ) ) {
			return array_map( [ self::class, 'unslash' ], $value );
		}

		return is_string( $value ) ? stripslashes( $value ) : $value;
	}

	/**
	 * @return array{text: string, is_correct: bool, explanation: string}
	 */
	private function answer( string $text, bool $is_correct ): array {
		return [
			'text'        => $text,
			'is_correct'  => $is_correct,
			'explanation' => '',
		];
	}

	/**
	 * @param string $fixture A fixture name, or an absolute path from {@see self::temp_copy()}.
	 */
	private function run_import( string $fixture ): ImportResult {
		$plan = $this->plan( str_starts_with( $fixture, '/' ) ? $fixture : self::FIXTURES . $fixture );

		return ( new Importer() )->run( $plan, new ImportContext( self::TOKEN, self::INSTRUCTOR_ID, self::ADMIN_ID ) );
	}

	private function plan( string $path ): ImportPlan {
		$markdown_to_html = new MarkdownToHtml();
		$service          = new ImportService(
			new CourseDocumentParser( new FrontMatterParser(), new QuizBlockParser() ),
			new CourseValidator( $markdown_to_html ),
			new CourseHtmlBuilder( $markdown_to_html ),
			new ModuleHtmlBuilder(),
			new LessonHtmlBuilder( $markdown_to_html ),
			new Importer(),
			70
		);

		$plan = $service->analyse( $path )->plan;
		self::assertNotNull( $plan );

		return $plan;
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
