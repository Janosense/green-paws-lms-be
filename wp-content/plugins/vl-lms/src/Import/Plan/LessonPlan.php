<?php

declare(strict_types=1);

namespace VL\LMS\Import\Plan;

/**
 * A `vl_lesson`, under its module or, in a course without modules, under the course.
 *
 * @author Tymofii Synianskyi
 */
final readonly class LessonPlan {

	/**
	 * @param string $title      The lesson title without «Урок N.M.».
	 * @param string $html       The lesson sections as plain HTML.
	 * @param int    $menu_order `M` of «Урок N.M.», or `N` of «Урок N.».
	 */
	public function __construct(
		public string $title,
		public string $html,
		public int $menu_order
	) {
	}
}
