<?php

declare(strict_types=1);

namespace VL\LMS\Import\Document;

/**
 * A `# Модуль N.` module with the lessons and the quiz that follow its heading.
 *
 * @author Tymofii Synianskyi
 */
final readonly class ModuleSpec {

	/**
	 * @param int              $number  The `N` of `Модуль N.`.
	 * @param string           $title   The heading text after the number.
	 * @param int              $line    1-based file line of the heading.
	 * @param list<LessonSpec> $lessons In file order.
	 * @param QuizSpec|null    $quiz    The module's `## Тест модуля N`, if any.
	 */
	public function __construct(
		public int $number,
		public string $title,
		public int $line,
		public array $lessons,
		public ?QuizSpec $quiz
	) {
	}
}
