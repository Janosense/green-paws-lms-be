<?php

declare(strict_types=1);

namespace VL\LMS\Import\Validation;

use VL\LMS\Import\Convert\MarkdownToHtml;
use VL\LMS\Import\Document\CourseDocument;
use VL\LMS\Import\Document\FrontMatter;
use VL\LMS\Import\Document\FrontMatterEntry;
use VL\LMS\Import\Document\FrontMatterType;
use VL\LMS\Import\Document\LessonSpec;
use VL\LMS\Import\Document\ModuleSpec;
use VL\LMS\Import\Document\QuestionSpec;
use VL\LMS\Import\Document\QuizSpec;
use VL\LMS\Import\Document\SectionSpec;
use VL\LMS\Import\Issue\ImportIssue;
use VL\LMS\Import\Issue\IssueList;

/**
 * Checks a parsed `course.md` against the rules of
 * `docs/features/course-import/format/COURSE-FORMAT.md` that its tree can
 * express, and appends the findings to the document's issue list.
 *
 * Runs only on a document the parser built without errors — after a parse
 * error the tree is missing the rejected headings and their children, and
 * findings on it would include false errors (course-import FEATURE.md →
 * Invariants).
 *
 * - Frontmatter: required keys, value types, allowed values; unknown keys and
 *   categories / tags the site does not have yet are warnings.
 * - Structure: exactly one course H1; module, lesson and question numbers run
 *   from 1 without gaps or repeats, each break reported once; the three
 *   mandatory lesson sections; a test after every module but the last (the
 *   last one's is optional — the format's template has one), the final test
 *   after everything; no quiz without questions (a quiz with no questions can
 *   never be passed); 2–6 options and at least one `[x]` per question.
 * - Bodies: raw HTML as CommonMark reads it; `[УТОЧНИТИ]` (an error with
 *   `status: ready`, a warning otherwise); «Позиції [УТОЧНИТИ]» must be empty
 *   with `status: ready`; «Структура курсу» gets a note that it is not imported.
 *
 * @author Tymofii Synianskyi
 */
final class CourseValidator {

	public const MISSING_KEY          = 'frontmatter.missing_key';
	public const WRONG_TYPE           = 'frontmatter.wrong_type';
	public const NOT_ALLOWED          = 'frontmatter.not_allowed';
	public const UNKNOWN_KEY          = 'frontmatter.unknown_key';
	public const NEW_TERM             = 'taxonomy.new_term';
	public const TITLE_HEADING_COUNT  = 'title.heading_count';
	public const TITLE_MISMATCH       = 'title.mismatch';
	public const MODULE_NUMBER        = 'numbering.module';
	public const LESSON_NUMBER        = 'numbering.lesson';
	public const LESSON_MODULE_NUMBER = 'numbering.lesson_module';
	public const QUIZ_MODULE_NUMBER   = 'numbering.quiz_module';
	public const QUESTION_NUMBER      = 'numbering.question';
	public const MISSING_SECTION      = 'lesson.missing_section';
	public const MISSING_MODULE_QUIZ  = 'quiz.missing_module_quiz';
	public const MISSING_FINAL_QUIZ   = 'quiz.missing_final';
	public const MISPLACED_QUIZ       = 'quiz.misplaced';
	public const EMPTY_QUIZ           = 'quiz.no_questions';
	public const NO_CORRECT_OPTION    = 'question.no_correct';
	public const OPTION_COUNT         = 'question.option_count';
	public const RAW_HTML             = 'body.raw_html';
	public const CLARIFY_MARKER       = 'body.clarify_marker';
	public const OPEN_ITEMS_NOT_EMPTY = 'open_items.not_empty';
	public const STRUCTURE_IGNORED    = 'section.structure_ignored';

	/**
	 * COURSE-FORMAT.md §2: every key the format knows, whether it is required,
	 * and the type its value must be written in.
	 */
	private const FRONT_MATTER_KEYS = [
		'title'             => [
			'required' => true,
			'type'     => FrontMatterType::STRING,
		],
		'slug'              => [
			'required' => true,
			'type'     => FrontMatterType::STRING,
		],
		'author'            => [
			'required' => true,
			'type'     => FrontMatterType::STRING,
		],
		'author_org'        => [
			'required' => false,
			'type'     => FrontMatterType::STRING,
		],
		'level'             => [
			'required' => true,
			'type'     => FrontMatterType::STRING,
		],
		'language'          => [
			'required' => true,
			'type'     => FrontMatterType::STRING,
		],
		'status'            => [
			'required' => true,
			'type'     => FrontMatterType::STRING,
		],
		'version'           => [
			'required' => true,
			'type'     => FrontMatterType::INT,
		],
		'source_type'       => [
			'required' => false,
			'type'     => FrontMatterType::STRING,
		],
		'source_title'      => [
			'required' => false,
			'type'     => FrontMatterType::STRING,
		],
		'source_date'       => [
			'required' => false,
			'type'     => FrontMatterType::DATE,
		],
		'category'          => [
			'required' => false,
			'type'     => FrontMatterType::STRING,
		],
		'tags'              => [
			'required' => false,
			'type'     => FrontMatterType::LIST,
		],
		'duration_minutes'  => [
			'required' => false,
			'type'     => FrontMatterType::INT,
		],
		'quiz_pass_percent' => [
			'required' => false,
			'type'     => FrontMatterType::INT,
		],
	];

	/**
	 * Closed value lists of COURSE-FORMAT.md §1–§2 (`level` comes from {@see CourseLevel}).
	 */
	private const ALLOWED_VALUES = [
		'language'    => [ 'uk' ],
		'status'      => [ 'draft', 'review', 'ready' ],
		'source_type' => [ 'webinar', 'lecture', 'article', 'mixed' ],
	];

	/**
	 * COURSE-FORMAT.md §2: `slug` is latin kebab-case.
	 */
	private const SLUG_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

	/**
	 * The range of `_vl_quiz_passing_threshold` (`docs/DATA-MODEL.md`).
	 */
	private const MAX_PASS_PERCENT = 100;

	/**
	 * COURSE-FORMAT.md §5: options per question.
	 */
	private const MIN_OPTIONS = 2;
	private const MAX_OPTIONS = 6;

	private const MARKER       = '[УТОЧНИТИ]';
	private const STATUS_READY = 'ready';

	private const CATEGORY_TAXONOMY = 'vl_category';
	private const TAG_TAXONOMY      = 'vl_tag';

	public function __construct(
		private readonly MarkdownToHtml $markdown_to_html
	) {
	}

	public function validate( CourseDocument $document ): void {
		$issues = $document->issues;

		$this->check_front_matter( $document->front_matter, $issues );
		$this->check_title( $document, $issues );
		$this->check_modules( $document->modules, $issues );
		$this->check_lesson_numbers( $document->lessons, $issues );
		$this->check_final_quiz( $document, $issues );

		foreach ( $this->lessons( $document ) as $lesson ) {
			$this->check_lesson_sections( $lesson, $issues );
		}
		foreach ( $this->quizzes( $document ) as $quiz ) {
			$this->check_quiz( $quiz, $issues );
		}

		$this->check_bodies( $document, $issues );
	}

	private function check_front_matter( FrontMatter $front_matter, IssueList $issues ): void {
		foreach ( self::FRONT_MATTER_KEYS as $key => $rule ) {
			$entry = $front_matter->get( $key );

			if ( null === $entry ) {
				if ( $rule['required'] ) {
					$issues->add(
						ImportIssue::error(
							self::MISSING_KEY,
							null,
							/* translators: %s: frontmatter key, e.g. "slug" */
							sprintf( __( 'Немає обовʼязкового поля метаданих «%s».', 'vl-lms' ), $key )
						)
					);
				}
				continue;
			}

			if ( $rule['type'] !== $entry->type ) {
				$issues->add(
					ImportIssue::error(
						self::WRONG_TYPE,
						$entry->line,
						/* translators: 1: frontmatter key, e.g. "version"; 2: the expected kind of value, e.g. "цілим числом" */
						sprintf( __( 'Поле «%1$s» має бути %2$s.', 'vl-lms' ), $key, $this->type_label( $rule['type'] ) )
					)
				);
				continue;
			}

			$this->check_value( $entry, $issues );
		}

		foreach ( $front_matter->entries as $entry ) {
			if ( ! isset( self::FRONT_MATTER_KEYS[ $entry->key ] ) ) {
				$issues->add(
					ImportIssue::warning(
						self::UNKNOWN_KEY,
						$entry->line,
						/* translators: %s: frontmatter key as written */
						sprintf( __( 'Невідоме поле метаданих «%s» буде пропущено.', 'vl-lms' ), $entry->key )
					)
				);
			}
		}
	}

	/**
	 * Checks a value that already has the right type.
	 */
	private function check_value( FrontMatterEntry $entry, IssueList $issues ): void {
		$value   = $entry->value;
		$allowed = 'level' === $entry->key
			? array_map( static fn ( CourseLevel $level ): string => $level->value, CourseLevel::cases() )
			: self::ALLOWED_VALUES[ $entry->key ] ?? null;

		if ( null !== $allowed && is_string( $value ) && ! in_array( $value, $allowed, true ) ) {
			$issues->add(
				ImportIssue::error(
					self::NOT_ALLOWED,
					$entry->line,
					/* translators: 1: frontmatter key; 2: the value as written; 3: comma-separated allowed values */
					sprintf( __( 'Поле «%1$s» не може мати значення «%2$s». Дозволені значення: %3$s.', 'vl-lms' ), $entry->key, $value, implode( ', ', $allowed ) )
				)
			);
			return;
		}

		if ( 'slug' === $entry->key && is_string( $value ) && 1 !== preg_match( self::SLUG_PATTERN, $value ) ) {
			$issues->add(
				ImportIssue::error(
					self::NOT_ALLOWED,
					$entry->line,
					/* translators: %s: the slug as written */
					sprintf( __( 'Slug «%s» має складатися з малих латинських літер і цифр, розділених одинарними дефісами, наприклад anesthesia-basics.', 'vl-lms' ), $value )
				)
			);
			return;
		}

		if ( 'duration_minutes' === $entry->key && is_int( $value ) && $value < 0 ) {
			$issues->add(
				ImportIssue::error(
					self::NOT_ALLOWED,
					$entry->line,
					__( 'Поле «duration_minutes» не може бути відʼємним.', 'vl-lms' )
				)
			);
			return;
		}

		if ( 'quiz_pass_percent' === $entry->key && is_int( $value ) && ( $value < 0 || $value > self::MAX_PASS_PERCENT ) ) {
			$issues->add(
				ImportIssue::error(
					self::NOT_ALLOWED,
					$entry->line,
					/* translators: %d: the largest allowed percent, 100 */
					sprintf( __( 'Поле «quiz_pass_percent» має бути від 0 до %d.', 'vl-lms' ), self::MAX_PASS_PERCENT )
				)
			);
			return;
		}

		if ( 'category' === $entry->key && is_string( $value ) ) {
			$this->check_term( $entry, $value, self::CATEGORY_TAXONOMY, $issues );
		}

		if ( 'tags' === $entry->key && is_array( $value ) ) {
			foreach ( $value as $slug ) {
				$this->check_term( $entry, $slug, self::TAG_TAXONOMY, $issues );
			}
		}
	}

	/**
	 * A category or tag the site does not have yet is created by the import —
	 * the same `term_exists()` lookup the importer runs before `wp_insert_term()`.
	 */
	private function check_term( FrontMatterEntry $entry, string $slug, string $taxonomy, IssueList $issues ): void {
		if ( ! empty( term_exists( $slug, $taxonomy ) ) ) {
			return;
		}

		if ( self::CATEGORY_TAXONOMY === $taxonomy ) {
			/* translators: %s: category slug */
			$message = sprintf( __( 'Категорії «%s» на сайті ще немає — її буде створено під час імпорту.', 'vl-lms' ), $slug );
		} else {
			/* translators: %s: tag slug */
			$message = sprintf( __( 'Мітки «%s» на сайті ще немає — її буде створено під час імпорту.', 'vl-lms' ), $slug );
		}

		$issues->add( ImportIssue::warning( self::NEW_TERM, $entry->line, $message ) );
	}

	private function check_title( CourseDocument $document, IssueList $issues ): void {
		$headings = $document->title_headings;

		if ( [] === $headings ) {
			$issues->add(
				ImportIssue::error(
					self::TITLE_HEADING_COUNT,
					null,
					__( 'У файлі немає заголовка курсу: одразу після метаданих має стояти рядок «# Назва курсу».', 'vl-lms' )
				)
			);
			return;
		}

		foreach ( array_slice( $headings, 1 ) as $heading ) {
			$issues->add(
				ImportIssue::error(
					self::TITLE_HEADING_COUNT,
					$heading['line'],
					/* translators: %s: heading text */
					sprintf( __( 'Зайвий заголовок курсу «# %s»: заголовок першого рівня без префікса має бути лише один.', 'vl-lms' ), $heading['text'] )
				)
			);
		}

		$title = $document->front_matter->get( 'title' );
		if ( null !== $title && is_string( $title->value ) && $title->value !== $headings[0]['text'] ) {
			$issues->add(
				ImportIssue::warning(
					self::TITLE_MISMATCH,
					$headings[0]['line'],
					/* translators: 1: the H1 text; 2: the frontmatter title */
					sprintf( __( 'Заголовок курсу «%1$s» не збігається з полем title «%2$s». Назвою курсу буде title.', 'vl-lms' ), $headings[0]['text'], $title->value )
				)
			);
		}
	}

	/**
	 * @param list<ModuleSpec> $modules
	 */
	private function check_modules( array $modules, IssueList $issues ): void {
		$numbers = array_map(
			static fn ( ModuleSpec $module ): array => [
				'number' => $module->number,
				'line'   => $module->line,
			],
			$modules
		);

		foreach ( $this->sequence_breaks( $numbers ) as $break ) {
			$issues->add(
				ImportIssue::error(
					self::MODULE_NUMBER,
					$break['line'],
					/* translators: 1: the module number as written; 2: the number expected there */
					sprintf( __( 'Модуль має номер %1$d, а за порядком очікувався %2$d. Модулі нумеруються підряд від 1.', 'vl-lms' ), $break['number'], $break['expected'] )
				)
			);
		}

		$last = count( $modules ) - 1;

		foreach ( $modules as $index => $module ) {
			$this->check_lesson_numbers( $module->lessons, $issues );

			foreach ( $module->lessons as $lesson ) {
				if ( $lesson->module_number !== $module->number ) {
					$issues->add(
						ImportIssue::error(
							self::LESSON_MODULE_NUMBER,
							$lesson->line,
							/* translators: 1: module part of the lesson number; 2: lesson part of the lesson number; 3: the number of the module the lesson is in */
							sprintf( __( 'Урок %1$d.%2$d стоїть у модулі %3$d: перше число в номері уроку має бути номером модуля.', 'vl-lms' ), (int) $lesson->module_number, $lesson->number, $module->number )
						)
					);
				}
			}

			$quiz = $module->quiz;

			if ( null === $quiz ) {
				if ( $index !== $last ) {
					$issues->add(
						ImportIssue::error(
							self::MISSING_MODULE_QUIZ,
							$module->line,
							/* translators: %1$d: module number */
							sprintf( __( 'Після уроків модуля %1$d немає «## Тест модуля %1$d». Тест обовʼязковий для кожного модуля, крім останнього.', 'vl-lms' ), $module->number )
						)
					);
				}
				continue;
			}

			if ( $quiz->module_number !== $module->number ) {
				$issues->add(
					ImportIssue::error(
						self::QUIZ_MODULE_NUMBER,
						$quiz->line,
						/* translators: 1: the number in the quiz heading; 2: the number of the module the quiz is in */
						sprintf( __( '«Тест модуля %1$d» стоїть у модулі %2$d: номер тесту має збігатися з номером модуля.', 'vl-lms' ), (int) $quiz->module_number, $module->number )
					)
				);
			}

			foreach ( $module->lessons as $lesson ) {
				if ( $lesson->line > $quiz->line ) {
					$issues->add(
						ImportIssue::error(
							self::MISPLACED_QUIZ,
							$quiz->line,
							/* translators: %d: module number */
							sprintf( __( 'Тест модуля %d має стояти після всіх уроків свого модуля.', 'vl-lms' ), $module->number )
						)
					);
					break;
				}
			}
		}
	}

	/**
	 * @param list<LessonSpec> $lessons The lessons of one module, or of a course without modules.
	 */
	private function check_lesson_numbers( array $lessons, IssueList $issues ): void {
		$numbers = array_map(
			static fn ( LessonSpec $lesson ): array => [
				'number' => $lesson->number,
				'line'   => $lesson->line,
			],
			$lessons
		);

		foreach ( $this->sequence_breaks( $numbers ) as $break ) {
			$issues->add(
				ImportIssue::error(
					self::LESSON_NUMBER,
					$break['line'],
					/* translators: 1: the lesson number as written; 2: the number expected there */
					sprintf( __( 'Урок має порядковий номер %1$d, а очікувався %2$d. Уроки нумеруються підряд від 1 у межах модуля або курсу без модулів.', 'vl-lms' ), $break['number'], $break['expected'] )
				)
			);
		}
	}

	private function check_final_quiz( CourseDocument $document, IssueList $issues ): void {
		$final = $document->final_quiz;

		if ( null === $final ) {
			$issues->add(
				ImportIssue::error(
					self::MISSING_FINAL_QUIZ,
					null,
					__( 'У курсі немає «## Підсумковий тест». Він обовʼязковий і стоїть після останнього уроку.', 'vl-lms' )
				)
			);
			return;
		}

		$lines = array_map( static fn ( LessonSpec $lesson ): int => $lesson->line, $this->lessons( $document ) );
		foreach ( $document->modules as $module ) {
			$lines[] = $module->line;
			if ( null !== $module->quiz ) {
				$lines[] = $module->quiz->line;
			}
		}

		if ( [] !== $lines && max( $lines ) > $final->line ) {
			$issues->add(
				ImportIssue::error(
					self::MISPLACED_QUIZ,
					$final->line,
					__( '«Підсумковий тест» має стояти після всіх модулів, уроків і тестів модулів.', 'vl-lms' )
				)
			);
		}
	}

	private function check_lesson_sections( LessonSpec $lesson, IssueList $issues ): void {
		$mandatory = [
			'Цілі уроку'       => $lesson->objectives,
			'Зміст'            => $lesson->content,
			'Ключові висновки' => $lesson->takeaways,
		];

		foreach ( $mandatory as $name => $section ) {
			if ( null === $section ) {
				$issues->add(
					ImportIssue::error(
						self::MISSING_SECTION,
						$lesson->line,
						/* translators: %s: section name, e.g. "Зміст" */
						sprintf( __( 'В уроці немає обовʼязкового розділу «### %s».', 'vl-lms' ), $name )
					)
				);
			} elseif ( '' === trim( $section->body ) ) {
				$issues->add(
					ImportIssue::error(
						self::MISSING_SECTION,
						$section->heading_line,
						/* translators: %s: section name, e.g. "Зміст" */
						sprintf( __( 'Обовʼязковий розділ «### %s» порожній.', 'vl-lms' ), $name )
					)
				);
			}
		}
	}

	private function check_quiz( QuizSpec $quiz, IssueList $issues ): void {
		if ( [] === $quiz->questions ) {
			$issues->add(
				ImportIssue::error(
					self::EMPTY_QUIZ,
					$quiz->line,
					/* translators: %s: quiz heading, e.g. "Підсумковий тест" */
					sprintf( __( 'У тесті «%s» немає жодного запитання, а такий тест неможливо скласти.', 'vl-lms' ), $quiz->section->heading )
				)
			);
			return;
		}

		$numbers = array_map(
			static fn ( QuestionSpec $question ): array => [
				'number' => $question->number,
				'line'   => $question->line,
			],
			$quiz->questions
		);

		foreach ( $this->sequence_breaks( $numbers ) as $break ) {
			$issues->add(
				ImportIssue::error(
					self::QUESTION_NUMBER,
					$break['line'],
					/* translators: 1: the question number as written; 2: the number expected there */
					sprintf( __( 'Запитання має номер %1$d, а за порядком очікувався %2$d. Запитання в тесті нумеруються підряд від 1.', 'vl-lms' ), $break['number'], $break['expected'] )
				)
			);
		}

		foreach ( $quiz->questions as $question ) {
			$options = count( $question->options );

			if ( $options < self::MIN_OPTIONS || $options > self::MAX_OPTIONS ) {
				$issues->add(
					ImportIssue::error(
						self::OPTION_COUNT,
						$question->line,
						/* translators: 1: question number; 2: how many options it has; 3: minimum options; 4: maximum options */
						sprintf( __( 'Запитання %1$d має варіантів відповіді: %2$d, а має бути від %3$d до %4$d.', 'vl-lms' ), $question->number, $options, self::MIN_OPTIONS, self::MAX_OPTIONS )
					)
				);
			}

			if ( [] === array_filter( array_column( $question->options, 'correct' ) ) ) {
				$issues->add(
					ImportIssue::error(
						self::NO_CORRECT_OPTION,
						$question->line,
						/* translators: %d: question number */
						sprintf( __( 'Запитання %d не має правильної відповіді: позначте хоча б один варіант як «- [x]».', 'vl-lms' ), $question->number )
					)
				);
			}
		}
	}

	private function check_bodies( CourseDocument $document, IssueList $issues ): void {
		$status   = $document->front_matter->get( 'status' );
		$is_ready = null !== $status && self::STATUS_READY === $status->value;

		foreach ( $this->sections( $document ) as $section ) {
			foreach ( $this->markdown_to_html->raw_html_lines( $section->body, $section->body_line ) as $line ) {
				$issues->add(
					ImportIssue::error(
						self::RAW_HTML,
						$line,
						__( 'Сирий HTML у тексті курсу заборонений. Замініть теги розміткою Markdown або приберіть їх.', 'vl-lms' )
					)
				);
			}

			// «Позиції [УТОЧНИТИ]» lists the markers by design; its own rule is below.
			if ( $section === $document->open_items ) {
				continue;
			}

			foreach ( explode( "\n", $section->body ) as $offset => $text ) {
				if ( ! str_contains( $text, self::MARKER ) ) {
					continue;
				}

				$issues->add(
					$is_ready
						? ImportIssue::error( self::CLARIFY_MARKER, $section->body_line + $offset, __( 'У тексті лишилася позначка [УТОЧНИТИ], а курс зі статусом ready не може її містити.', 'vl-lms' ) )
						: ImportIssue::warning( self::CLARIFY_MARKER, $section->body_line + $offset, __( 'У тексті є позначка [УТОЧНИТИ]: перед статусом ready її треба прибрати.', 'vl-lms' ) )
				);
			}
		}

		$open_items = $document->open_items;
		if ( $is_ready && null !== $open_items && '' !== trim( $open_items->body ) ) {
			$issues->add(
				ImportIssue::error(
					self::OPEN_ITEMS_NOT_EMPTY,
					$open_items->heading_line,
					__( 'Курс зі статусом ready не може мати заповненого розділу «Позиції [УТОЧНИТИ]»: закрийте всі позиції або змініть статус на draft чи review.', 'vl-lms' )
				)
			);
		}

		if ( null !== $document->structure ) {
			$issues->add(
				ImportIssue::info(
					self::STRUCTURE_IGNORED,
					$document->structure->heading_line,
					__( 'Розділ «Структура курсу» не імпортується: структуру курсу буде створено із заголовків модулів, уроків і тестів.', 'vl-lms' )
				)
			);
		}
	}

	/**
	 * Numbers that break a run counting up from 1. Each number is compared with
	 * the previous one + 1 (the first with 1), so a gap or a repeat is reported
	 * once rather than for every item after it.
	 *
	 * @param list<array{number: int, line: int}> $items In file order.
	 *
	 * @return list<array{number: int, expected: int, line: int}>
	 */
	private function sequence_breaks( array $items ): array {
		$breaks   = [];
		$previous = 0;

		foreach ( $items as $item ) {
			if ( $previous + 1 !== $item['number'] ) {
				$breaks[] = [
					'number'   => $item['number'],
					'expected' => $previous + 1,
					'line'     => $item['line'],
				];
			}
			$previous = $item['number'];
		}

		return $breaks;
	}

	/**
	 * @return list<LessonSpec> Module lessons in file order, then the lessons of a course without modules.
	 */
	private function lessons( CourseDocument $document ): array {
		$lessons = [];
		foreach ( $document->modules as $module ) {
			array_push( $lessons, ...$module->lessons );
		}

		return array_merge( $lessons, $document->lessons );
	}

	/**
	 * @return list<QuizSpec>
	 */
	private function quizzes( CourseDocument $document ): array {
		$quizzes = [];
		foreach ( $document->modules as $module ) {
			if ( null !== $module->quiz ) {
				$quizzes[] = $module->quiz;
			}
		}
		if ( null !== $document->final_quiz ) {
			$quizzes[] = $document->final_quiz;
		}

		return $quizzes;
	}

	/**
	 * Every section body of the document, quiz sections included.
	 *
	 * @return list<SectionSpec>
	 */
	private function sections( CourseDocument $document ): array {
		$sections = [ $document->about, $document->goals, $document->structure, $document->literature, $document->open_items ];

		foreach ( $this->lessons( $document ) as $lesson ) {
			array_push( $sections, $lesson->objectives, $lesson->content, $lesson->takeaways, $lesson->materials );
		}
		foreach ( $this->quizzes( $document ) as $quiz ) {
			$sections[] = $quiz->section;
		}

		return array_values( array_filter( $sections ) );
	}

	private function type_label( FrontMatterType $type ): string {
		return match ( $type ) {
			FrontMatterType::STRING => __( 'текстом', 'vl-lms' ),
			FrontMatterType::INT    => __( 'цілим числом', 'vl-lms' ),
			FrontMatterType::DATE   => __( 'датою у форматі РРРР-ММ-ДД', 'vl-lms' ),
			FrontMatterType::LIST   => __( 'списком у квадратних дужках, наприклад [a, b]', 'vl-lms' ),
		};
	}
}
