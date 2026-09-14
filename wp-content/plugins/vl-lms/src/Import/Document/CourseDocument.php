<?php

declare(strict_types=1);

namespace VL\LMS\Import\Document;

use VL\LMS\Import\Issue\IssueList;

/**
 * The parse tree of one `course.md`, as written, plus the parse issues.
 *
 * Produced by {@see \VL\LMS\Import\Parser\CourseDocumentParser}; in memory
 * only, never stored. Nothing here is validated beyond what the parser needs
 * to build the tree: numbering, mandatory sections, test placement and the
 * question rules are the validator's (course-import FEATURE.md → Invariants).
 *
 * @author Tymofii Synianskyi
 */
final readonly class CourseDocument {

	/**
	 * @param FrontMatter                          $front_matter   Valid frontmatter entries.
	 * @param list<array{text: string, line: int}> $title_headings Plain `#` headings before the first module, lesson or quiz.
	 * @param SectionSpec|null                     $about          `## Про курс`.
	 * @param SectionSpec|null                     $goals          `## Мета курсу`.
	 * @param SectionSpec|null                     $structure      `## Структура курсу`.
	 * @param CourseVariant|null                   $variant        Null when the file has no module, lesson or module quiz heading.
	 * @param list<ModuleSpec>                     $modules        Modules variant.
	 * @param list<LessonSpec>                     $lessons        Lessons of a course without modules.
	 * @param QuizSpec|null                        $final_quiz     `## Підсумковий тест`.
	 * @param SectionSpec|null                     $literature     `## Література`.
	 * @param SectionSpec|null                     $open_items     `## Позиції [УТОЧНИТИ]`.
	 * @param IssueList                            $issues         Parse issues; the validator appends to the same list.
	 */
	public function __construct(
		public FrontMatter $front_matter,
		public array $title_headings,
		public ?SectionSpec $about,
		public ?SectionSpec $goals,
		public ?SectionSpec $structure,
		public ?CourseVariant $variant,
		public array $modules,
		public array $lessons,
		public ?QuizSpec $final_quiz,
		public ?SectionSpec $literature,
		public ?SectionSpec $open_items,
		public IssueList $issues
	) {
	}
}
