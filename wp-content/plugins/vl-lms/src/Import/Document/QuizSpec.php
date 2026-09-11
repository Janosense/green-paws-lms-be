<?php

declare(strict_types=1);

namespace VL\LMS\Import\Document;

/**
 * A module quiz or the final quiz: its heading, raw section and questions.
 *
 * @author Tymofii Synianskyi
 */
final readonly class QuizSpec {

	/**
	 * @param QuizKind           $kind          Module quiz or final quiz.
	 * @param int|null           $module_number The `N` of `## Тест модуля N`; null for the final quiz.
	 * @param int                $line          1-based file line of the heading.
	 * @param SectionSpec        $section       The raw section the questions were parsed from.
	 * @param list<QuestionSpec> $questions     In file order.
	 */
	public function __construct(
		public QuizKind $kind,
		public ?int $module_number,
		public int $line,
		public SectionSpec $section,
		public array $questions
	) {
	}
}
