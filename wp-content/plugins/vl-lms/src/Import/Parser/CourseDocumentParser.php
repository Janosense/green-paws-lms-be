<?php

declare(strict_types=1);

namespace VL\LMS\Import\Parser;

use VL\LMS\Import\Document\CourseDocument;
use VL\LMS\Import\Document\CourseVariant;
use VL\LMS\Import\Document\FrontMatter;
use VL\LMS\Import\Document\LessonSpec;
use VL\LMS\Import\Document\ModuleSpec;
use VL\LMS\Import\Document\QuizKind;
use VL\LMS\Import\Document\QuizSpec;
use VL\LMS\Import\Document\SectionSpec;
use VL\LMS\Import\Issue\ImportIssue;
use VL\LMS\Import\Issue\IssueList;

/**
 * Splits a `course.md` into its parse tree
 * (`docs/features/course-import/format/COURSE-FORMAT.md` §1–§6).
 *
 * Line-oriented and pure: no filesystem, no WordPress call besides `__()`,
 * the same input always yields the same tree. The parser reports only what
 * stops it from building the tree faithfully — broken frontmatter, headings
 * outside the fixed prefixes or in the wrong place, mixed variants, repeated
 * sections, text that belongs to no section. Every rule the tree can express
 * (numbering, mandatory sections, test placement, question rules, raw HTML,
 * `[УТОЧНИТИ]`) is the validator's.
 *
 * - Input: a leading UTF-8 BOM is dropped and CR LF / CR become LF; invalid
 *   UTF-8 stops parsing.
 * - Frontmatter: line 1 `---` up to the next `---`, handed to
 *   {@see FrontMatterParser}.
 * - Body: HTML comments are blanked with their line breaks kept, `---` rules
 *   are blanked; ATX headings of levels 1–3 (up to three leading spaces)
 *   open sections, `####` and deeper are body text.
 * - Lines under a heading the parser rejected are skipped, and so are the
 *   headings that could only belong to it (the lessons and quiz of a rejected
 *   module, the sections of a rejected lesson) — one issue per mistake.
 *
 * @author Tymofii Synianskyi
 */
final class CourseDocumentParser {

	public const INVALID_ENCODING     = 'document.invalid_encoding';
	public const FRONTMATTER_MISSING  = 'frontmatter.missing';
	public const FRONTMATTER_UNCLOSED = 'frontmatter.unclosed';
	public const UNCLOSED_COMMENT     = 'structure.unclosed_comment';
	public const UNKNOWN_HEADING      = 'structure.unknown_heading';
	public const MISPLACED_HEADING    = 'structure.misplaced_heading';
	public const MIXED_VARIANTS       = 'structure.mixed_variants';
	public const DUPLICATE_SECTION    = 'structure.duplicate_section';
	public const ORPHAN_CONTENT       = 'structure.orphan_content';

	/**
	 * `##` course sections → the {@see CourseDocument} property they fill.
	 */
	private const DOCUMENT_SECTIONS = [
		'Про курс'           => 'about',
		'Мета курсу'         => 'goals',
		'Структура курсу'    => 'structure',
		'Література'         => 'literature',
		'Позиції [УТОЧНИТИ]' => 'open_items',
	];

	private const FINAL_QUIZ_HEADING = 'Підсумковий тест';

	/**
	 * `###` lesson sections → the {@see LessonSpec} property they fill.
	 */
	private const LESSON_SECTIONS = [
		'Цілі уроку'         => 'objectives',
		'Зміст'              => 'content',
		'Ключові висновки'   => 'takeaways',
		'Матеріали до уроку' => 'materials',
	];

	public function __construct(
		private readonly FrontMatterParser $front_matter_parser,
		private readonly QuizBlockParser $quiz_block_parser
	) {
	}

	public function parse( string $contents ): CourseDocument {
		$issues = new IssueList();

		if ( 1 !== preg_match( '//u', $contents ) ) {
			$issues->add(
				ImportIssue::error(
					self::INVALID_ENCODING,
					$this->first_invalid_utf8_line( $contents ),
					__( 'Файл не в кодуванні UTF-8. Збережіть його в UTF-8 і завантажте знову.', 'vl-lms' )
				)
			);

			return $this->empty_document( $issues );
		}

		if ( str_starts_with( $contents, "\u{FEFF}" ) ) {
			$contents = substr( $contents, 3 );
		}
		$lines = explode( "\n", str_replace( [ "\r\n", "\r" ], "\n", $contents ) );

		$front_matter = new FrontMatter();
		$body_index   = 0;

		if ( '---' === rtrim( $lines[0] ) ) {
			$closing = $this->closing_delimiter( $lines );
			if ( null === $closing ) {
				$issues->add(
					ImportIssue::error(
						self::FRONTMATTER_UNCLOSED,
						1,
						__( 'Блок метаданих не закрито: після першого рядка «---» немає закривного «---».', 'vl-lms' )
					)
				);

				return $this->empty_document( $issues );
			}

			$front_matter = $this->front_matter_parser->parse( array_slice( $lines, 1, $closing - 1 ), 2, $issues );
			$body_index   = $closing + 1;
		} else {
			$issues->add(
				ImportIssue::error(
					self::FRONTMATTER_MISSING,
					1,
					__( 'Файл має починатися з метаданих курсу — блоку між двома рядками «---».', 'vl-lms' )
				)
			);
		}

		$body_first_line = $body_index + 1;
		$body_lines      = $this->blank_comments( array_slice( $lines, $body_index ), $body_first_line, $issues );

		return $this->parse_body( $front_matter, $this->split_blocks( $body_lines, $body_first_line ), $issues );
	}

	/**
	 * @param list<array{level: int, text: string, line: int, lines: list<string>}> $blocks
	 */
	private function parse_body( FrontMatter $front_matter, array $blocks, IssueList $issues ): CourseDocument {
		/** @var list<array{text: string, line: int}> $title_headings */
		$title_headings = [];
		/** @var array<string, SectionSpec|null> $sections */
		$sections   = array_fill_keys( array_values( self::DOCUMENT_SECTIONS ), null );
		$variant    = null;
		$final_quiz = null;
		/** @var list<array{number: int, title: string, line: int, quiz: QuizSpec|null}> $modules */
		$modules = [];
		/** @var list<array{module: int|null, module_number: int|null, number: int, title: string, line: int, sections: array<string, SectionSpec|null>}> $lessons */
		$lessons = [];

		$open_module          = null;
		$open_lesson          = null;
		$structure_started    = false;
		$skip_module_children = false;
		$skip_lesson_children = false;

		foreach ( $blocks as $block ) {
			$level = $block['level'];

			if ( 0 === $level ) {
				$this->report_orphans( $block, $issues );
				continue;
			}

			if ( 3 === $level && $skip_lesson_children ) {
				continue;
			}
			if ( $level <= 2 ) {
				$open_lesson          = null;
				$skip_lesson_children = false;
			}
			if ( 1 === $level ) {
				$open_module          = null;
				$skip_module_children = false;
			}

			$heading = $this->classify( $level, $block['text'] );
			$kind    = $heading['kind'];

			if ( in_array( $kind, [ 'module', 'module_lesson', 'flat_lesson', 'module_quiz', 'final_quiz' ], true ) ) {
				$structure_started = true;
			}

			if ( 'module' === $kind ) {
				if ( CourseVariant::FLAT === $variant ) {
					$this->reject_mixed( $block, $issues );
					$skip_module_children = true;
					continue;
				}

				$variant     = CourseVariant::MODULES;
				$modules[]   = [
					'number' => $heading['number'],
					'title'  => $heading['title'],
					'line'   => $block['line'],
					'quiz'   => null,
				];
				$open_module = array_key_last( $modules );
				$this->report_orphans( $block, $issues );
				continue;
			}

			if ( 'plain' === $kind && ! $structure_started ) {
				$title_headings[] = [
					'text' => $block['text'],
					'line' => $block['line'],
				];
				$this->report_orphans( $block, $issues );
				continue;
			}

			if ( 'module_lesson' === $kind || 'module_quiz' === $kind ) {
				if ( $skip_module_children ) {
					$skip_lesson_children = true;
					continue;
				}
				if ( CourseVariant::FLAT === $variant ) {
					$this->reject_mixed( $block, $issues );
					$skip_lesson_children = true;
					continue;
				}

				$variant = CourseVariant::MODULES;

				if ( null === $open_module ) {
					$issues->add(
						ImportIssue::error(
							self::MISPLACED_HEADING,
							$block['line'],
							/* translators: %s: the heading as written, e.g. "## Урок 1.1. Назва" */
							sprintf( __( 'Заголовок «%s» має стояти всередині модуля, після «# Модуль N. …».', 'vl-lms' ), $this->label( $block ) )
						)
					);
					$skip_lesson_children = true;
					continue;
				}

				if ( 'module_quiz' === $kind ) {
					if ( null !== $modules[ $open_module ]['quiz'] ) {
						$this->reject_duplicate( $block, $issues );
						$skip_lesson_children = true;
						continue;
					}

					$modules[ $open_module ]['quiz'] = $this->quiz( QuizKind::MODULE, $heading['number'], $block, $issues );
					continue;
				}

				$lessons[]   = $this->lesson( $open_module, $heading['module_number'], $heading, $block );
				$open_lesson = array_key_last( $lessons );
				$this->report_orphans( $block, $issues );
				continue;
			}

			if ( 'flat_lesson' === $kind ) {
				if ( CourseVariant::MODULES === $variant ) {
					$this->reject_mixed( $block, $issues );
					$skip_lesson_children = true;
					continue;
				}

				$variant     = CourseVariant::FLAT;
				$lessons[]   = $this->lesson( null, null, $heading, $block );
				$open_lesson = array_key_last( $lessons );
				$this->report_orphans( $block, $issues );
				continue;
			}

			if ( 'final_quiz' === $kind ) {
				if ( null !== $final_quiz ) {
					$this->reject_duplicate( $block, $issues );
					$skip_lesson_children = true;
					continue;
				}

				$final_quiz = $this->quiz( QuizKind::FINAL, null, $block, $issues );
				continue;
			}

			if ( 'document_section' === $kind ) {
				if ( null !== $sections[ $heading['slot'] ] ) {
					$this->reject_duplicate( $block, $issues );
					$skip_lesson_children = true;
					continue;
				}

				$sections[ $heading['slot'] ] = $this->section( $block );
				continue;
			}

			if ( 'lesson_section' === $kind ) {
				if ( null === $open_lesson ) {
					$issues->add(
						ImportIssue::error(
							self::MISPLACED_HEADING,
							$block['line'],
							/* translators: %s: the heading as written, e.g. "### Зміст" */
							sprintf( __( 'Розділ «%s» має стояти всередині уроку, після «## Урок …».', 'vl-lms' ), $this->label( $block ) )
						)
					);
					continue;
				}
				if ( null !== $lessons[ $open_lesson ]['sections'][ $heading['slot'] ] ) {
					$this->reject_duplicate( $block, $issues );
					continue;
				}

				$lessons[ $open_lesson ]['sections'][ $heading['slot'] ] = $this->section( $block );
				continue;
			}

			// An unknown heading, or a plain `#` heading after the course structure started.
			$issues->add(
				ImportIssue::error(
					self::UNKNOWN_HEADING,
					$block['line'],
					/* translators: %s: the heading as written, e.g. "## Додаток" */
					sprintf( __( 'Невідомий заголовок «%s». На рівнях #, ## і ### дозволені лише заголовки з фіксованими назвами та префіксами зі специфікації формату.', 'vl-lms' ), $this->label( $block ) )
				)
			);
			if ( 1 === $level ) {
				$skip_module_children = true;
			}
			if ( $level <= 2 ) {
				$skip_lesson_children = true;
			}
		}

		return $this->build_document( $front_matter, $title_headings, $sections, $variant, $modules, $lessons, $final_quiz, $issues );
	}

	/**
	 * @param list<array{text: string, line: int}>                                                                                                  $title_headings
	 * @param array<string, SectionSpec|null>                                                                                                       $sections
	 * @param list<array{number: int, title: string, line: int, quiz: QuizSpec|null}>                                                               $modules
	 * @param list<array{module: int|null, module_number: int|null, number: int, title: string, line: int, sections: array<string, SectionSpec|null>}> $lessons
	 */
	private function build_document(
		FrontMatter $front_matter,
		array $title_headings,
		array $sections,
		?CourseVariant $variant,
		array $modules,
		array $lessons,
		?QuizSpec $final_quiz,
		IssueList $issues
	): CourseDocument {
		$module_specs = [];
		foreach ( $modules as $module_index => $module ) {
			$module_specs[] = new ModuleSpec(
				$module['number'],
				$module['title'],
				$module['line'],
				$this->lesson_specs( $lessons, $module_index ),
				$module['quiz']
			);
		}

		return new CourseDocument(
			$front_matter,
			$title_headings,
			$sections['about'],
			$sections['goals'],
			$sections['structure'],
			$variant,
			$module_specs,
			$this->lesson_specs( $lessons, null ),
			$final_quiz,
			$sections['literature'],
			$sections['open_items'],
			$issues
		);
	}

	/**
	 * @param list<array{module: int|null, module_number: int|null, number: int, title: string, line: int, sections: array<string, SectionSpec|null>}> $lessons
	 * @param int|null                                                                                                                                  $module_index The module the lessons belong to; null for a course without modules.
	 *
	 * @return list<LessonSpec>
	 */
	private function lesson_specs( array $lessons, ?int $module_index ): array {
		$specs = [];
		foreach ( $lessons as $lesson ) {
			if ( $module_index !== $lesson['module'] ) {
				continue;
			}

			$specs[] = new LessonSpec(
				$lesson['module_number'],
				$lesson['number'],
				$lesson['title'],
				$lesson['line'],
				$lesson['sections']['objectives'],
				$lesson['sections']['content'],
				$lesson['sections']['takeaways'],
				$lesson['sections']['materials']
			);
		}

		return $specs;
	}

	/**
	 * What a heading is, from its level and text alone.
	 *
	 * @return array{kind: string, number: int, module_number: int, title: string, slot: string}
	 */
	private function classify( int $level, string $text ): array {
		$heading = [
			'kind'          => 'unknown',
			'number'        => 0,
			'module_number' => 0,
			'title'         => '',
			'slot'          => '',
		];

		if ( 1 === $level ) {
			if ( 1 === preg_match( '/^Модуль[ \t]+([0-9]+)\.[ \t]+(.*\S)$/u', $text, $matches ) ) {
				return array_merge(
					$heading,
					[
						'kind'   => 'module',
						'number' => (int) $matches[1],
						'title'  => $matches[2],
					]
				);
			}

			// A section name at the wrong level, or a numbered prefix that does not
			// match its pattern (`# Модуль 1 Вступ`), is never a course title.
			$reserved = isset( self::DOCUMENT_SECTIONS[ $text ] )
				|| isset( self::LESSON_SECTIONS[ $text ] )
				|| self::FINAL_QUIZ_HEADING === $text
				|| 1 === preg_match( '/^(Модуль|Урок|Тест модуля)[ \t]*[0-9]/u', $text );

			return ( '' === $text || $reserved ) ? $heading : array_merge( $heading, [ 'kind' => 'plain' ] );
		}

		if ( 2 === $level ) {
			if ( self::FINAL_QUIZ_HEADING === $text ) {
				return array_merge( $heading, [ 'kind' => 'final_quiz' ] );
			}
			if ( isset( self::DOCUMENT_SECTIONS[ $text ] ) ) {
				return array_merge(
					$heading,
					[
						'kind' => 'document_section',
						'slot' => self::DOCUMENT_SECTIONS[ $text ],
					]
				);
			}
			if ( 1 === preg_match( '/^Урок[ \t]+([0-9]+)\.([0-9]+)\.[ \t]+(.*\S)$/u', $text, $matches ) ) {
				return array_merge(
					$heading,
					[
						'kind'          => 'module_lesson',
						'module_number' => (int) $matches[1],
						'number'        => (int) $matches[2],
						'title'         => $matches[3],
					]
				);
			}
			if ( 1 === preg_match( '/^Урок[ \t]+([0-9]+)\.[ \t]+(.*\S)$/u', $text, $matches ) ) {
				return array_merge(
					$heading,
					[
						'kind'   => 'flat_lesson',
						'number' => (int) $matches[1],
						'title'  => $matches[2],
					]
				);
			}
			if ( 1 === preg_match( '/^Тест модуля[ \t]+([0-9]+)$/u', $text, $matches ) ) {
				return array_merge(
					$heading,
					[
						'kind'   => 'module_quiz',
						'number' => (int) $matches[1],
					]
				);
			}

			return $heading;
		}

		if ( isset( self::LESSON_SECTIONS[ $text ] ) ) {
			return array_merge(
				$heading,
				[
					'kind' => 'lesson_section',
					'slot' => self::LESSON_SECTIONS[ $text ],
				]
			);
		}

		return $heading;
	}

	/**
	 * Groups the body lines under their headings. The first block (level 0)
	 * holds the lines before the first heading.
	 *
	 * @param list<string> $lines
	 *
	 * @return list<array{level: int, text: string, line: int, lines: list<string>}>
	 */
	private function split_blocks( array $lines, int $first_line ): array {
		$blocks = [
			[
				'level' => 0,
				'text'  => '',
				'line'  => $first_line - 1,
				'lines' => [],
			],
		];

		foreach ( $lines as $index => $text ) {
			if ( 1 === preg_match( '/^ {0,3}(#{1,3})(?:[ \t]+(.*?))?[ \t]*$/u', $text, $matches ) ) {
				$blocks[] = [
					'level' => strlen( $matches[1] ),
					'text'  => $matches[2] ?? '',
					'line'  => $first_line + $index,
					'lines' => [],
				];
				continue;
			}

			$blocks[ array_key_last( $blocks ) ]['lines'][] = '---' === rtrim( $text ) ? '' : $text;
		}

		return $blocks;
	}

	/**
	 * Replaces every HTML comment with the line breaks it spanned. An
	 * unclosed `<!--` runs to the end of the file, as CommonMark reads it.
	 *
	 * @param list<string> $lines
	 *
	 * @return list<string>
	 */
	private function blank_comments( array $lines, int $first_line, IssueList $issues ): array {
		$text   = implode( "\n", $lines );
		$result = '';
		$offset = 0;

		while ( true ) {
			$start = strpos( $text, '<!--', $offset );
			if ( false === $start ) {
				$result .= substr( $text, $offset );
				break;
			}

			$result .= substr( $text, $offset, $start - $offset );
			$end     = strpos( $text, '-->', $start + 4 );

			if ( false === $end ) {
				$issues->add(
					ImportIssue::error(
						self::UNCLOSED_COMMENT,
						$first_line + substr_count( substr( $text, 0, $start ), "\n" ),
						__( 'Коментар «<!--» не закрито «-->». Усе після нього до кінця файлу пропущено.', 'vl-lms' )
					)
				);
				$result .= str_repeat( "\n", substr_count( substr( $text, $start ), "\n" ) );
				break;
			}

			$result .= str_repeat( "\n", substr_count( substr( $text, $start, $end + 3 - $start ), "\n" ) );
			$offset  = $end + 3;
		}

		return explode( "\n", $result );
	}

	/**
	 * @param array{level: int, text: string, line: int, lines: list<string>} $block
	 */
	private function section( array $block ): SectionSpec {
		$lines = $block['lines'];
		$first = 0;
		$last  = count( $lines ) - 1;

		while ( $first <= $last && '' === trim( $lines[ $first ] ) ) {
			++$first;
		}
		while ( $last >= $first && '' === trim( $lines[ $last ] ) ) {
			--$last;
		}

		if ( $first > $last ) {
			return new SectionSpec( $block['text'], $block['line'], '', $block['line'] + 1 );
		}

		return new SectionSpec(
			$block['text'],
			$block['line'],
			implode( "\n", array_slice( $lines, $first, $last - $first + 1 ) ),
			$block['line'] + 1 + $first
		);
	}

	/**
	 * @param array{level: int, text: string, line: int, lines: list<string>} $block
	 */
	private function quiz( QuizKind $kind, ?int $module_number, array $block, IssueList $issues ): QuizSpec {
		$section = $this->section( $block );

		return new QuizSpec(
			$kind,
			$module_number,
			$block['line'],
			$section,
			$this->quiz_block_parser->parse( $section->body, $section->body_line, $issues )
		);
	}

	/**
	 * @param array{kind: string, number: int, module_number: int, title: string, slot: string} $heading
	 * @param array{level: int, text: string, line: int, lines: list<string>}                   $block
	 *
	 * @return array{module: int|null, module_number: int|null, number: int, title: string, line: int, sections: array<string, SectionSpec|null>}
	 */
	private function lesson( ?int $module_index, ?int $module_number, array $heading, array $block ): array {
		return [
			'module'        => $module_index,
			'module_number' => $module_number,
			'number'        => $heading['number'],
			'title'         => $heading['title'],
			'line'          => $block['line'],
			'sections'      => array_fill_keys( array_values( self::LESSON_SECTIONS ), null ),
		];
	}

	/**
	 * Text under a heading that owns no body (title, module, lesson) or before
	 * the first heading maps to no entity — one issue per paragraph-like block.
	 *
	 * @param array{level: int, text: string, line: int, lines: list<string>} $block
	 */
	private function report_orphans( array $block, IssueList $issues ): void {
		$in_block = false;

		foreach ( $block['lines'] as $index => $text ) {
			if ( '' === trim( $text ) ) {
				$in_block = false;
				continue;
			}

			if ( ! $in_block ) {
				$issues->add(
					ImportIssue::error(
						self::ORPHAN_CONTENT,
						$block['line'] + 1 + $index,
						__( 'Текст поза розділами курсу не потрапить в імпорт. Перенесіть його в розділ або видаліть.', 'vl-lms' )
					)
				);
			}
			$in_block = true;
		}
	}

	/**
	 * @param array{level: int, text: string, line: int, lines: list<string>} $block
	 */
	private function reject_mixed( array $block, IssueList $issues ): void {
		$issues->add(
			ImportIssue::error(
				self::MIXED_VARIANTS,
				$block['line'],
				/* translators: %s: the heading as written, e.g. "## Урок 2. Назва" */
				sprintf( __( 'Заголовок «%s» належить іншому варіанту структури: курс з модулями і курс без модулів в одному файлі змішувати не можна.', 'vl-lms' ), $this->label( $block ) )
			)
		);
	}

	/**
	 * @param array{level: int, text: string, line: int, lines: list<string>} $block
	 */
	private function reject_duplicate( array $block, IssueList $issues ): void {
		$issues->add(
			ImportIssue::error(
				self::DUPLICATE_SECTION,
				$block['line'],
				/* translators: %s: the heading as written, e.g. "## Про курс" */
				sprintf( __( 'Заголовок «%s» повторюється, а такий розділ може бути лише один.', 'vl-lms' ), $this->label( $block ) )
			)
		);
	}

	/**
	 * @param array{level: int, text: string, line: int, lines: list<string>} $block
	 */
	private function label( array $block ): string {
		return rtrim( str_repeat( '#', $block['level'] ) . ' ' . $block['text'] );
	}

	/**
	 * @param list<string> $lines
	 */
	private function closing_delimiter( array $lines ): ?int {
		$count = count( $lines );
		for ( $index = 1; $index < $count; $index++ ) {
			if ( '---' === rtrim( $lines[ $index ] ) ) {
				return $index;
			}
		}

		return null;
	}

	private function first_invalid_utf8_line( string $contents ): int {
		// Splitting on the LF byte is safe: 0x0A never occurs inside a UTF-8 multibyte sequence.
		foreach ( explode( "\n", $contents ) as $index => $text ) {
			if ( 1 !== preg_match( '//u', $text ) ) {
				return $index + 1;
			}
		}

		return 1;
	}

	private function empty_document( IssueList $issues ): CourseDocument {
		return new CourseDocument( new FrontMatter(), [], null, null, null, null, [], [], null, null, null, $issues );
	}
}
