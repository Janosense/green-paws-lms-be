<?php

declare(strict_types=1);

namespace VL\LMS\Import\Document;

/**
 * A `## Урок N.M.` (or, without modules, `## Урок N.`) lesson and its raw
 * `###` sections. A section the file does not have is null — whether it is
 * mandatory is the validator's rule.
 *
 * @author Tymofii Synianskyi
 */
final readonly class LessonSpec {

	/**
	 * @param int|null         $module_number The `N` of `Урок N.M.` as written; null in a course without modules.
	 * @param int              $number        The `M` of `Урок N.M.`, or the `N` of `Урок N.`.
	 * @param string           $title         The heading text after the number.
	 * @param int              $line          1-based file line of the heading.
	 * @param SectionSpec|null $objectives    `### Цілі уроку`.
	 * @param SectionSpec|null $content       `### Зміст`.
	 * @param SectionSpec|null $takeaways     `### Ключові висновки`.
	 * @param SectionSpec|null $materials     `### Матеріали до уроку`.
	 */
	public function __construct(
		public ?int $module_number,
		public int $number,
		public string $title,
		public int $line,
		public ?SectionSpec $objectives,
		public ?SectionSpec $content,
		public ?SectionSpec $takeaways,
		public ?SectionSpec $materials
	) {
	}
}
