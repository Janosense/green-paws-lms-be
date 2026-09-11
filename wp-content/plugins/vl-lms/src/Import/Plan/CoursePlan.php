<?php

declare(strict_types=1);

namespace VL\LMS\Import\Plan;

/**
 * The `vl_course` an import creates: its post fields, terms and the meta
 * values taken from the file.
 *
 * @author Tymofii Synianskyi
 */
final readonly class CoursePlan {

	/**
	 * @param string       $title           Frontmatter `title` — the source of truth over the H1 (COURSE-FORMAT.md §3).
	 * @param string       $slug            Frontmatter `slug`, before the importer makes it unique.
	 * @param string       $html            «Про курс» + «Мета курсу» (+ «Література»).
	 * @param string       $difficulty_slug The `vl_difficulty` term mapped from `level`.
	 * @param string|null  $category_slug   Frontmatter `category`.
	 * @param list<string> $tag_slugs       Frontmatter `tags`.
	 * @param float|null   $duration_hours  `duration_minutes / 60` rounded to half an hour; null when the file has none.
	 * @param int          $pass_percent    `quiz_pass_percent`, or the configured default — the threshold of the course and every quiz.
	 */
	public function __construct(
		public string $title,
		public string $slug,
		public string $html,
		public string $difficulty_slug,
		public ?string $category_slug,
		public array $tag_slugs,
		public ?float $duration_hours,
		public int $pass_percent
	) {
	}
}
