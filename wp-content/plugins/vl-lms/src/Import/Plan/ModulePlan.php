<?php

declare(strict_types=1);

namespace VL\LMS\Import\Plan;

/**
 * A `vl_module` of the course, with its lessons and its quiz.
 *
 * @author Tymofii Synianskyi
 */
final readonly class ModulePlan {

	/**
	 * @param string           $title      The module title without «Модуль N.».
	 * @param string           $html       The title as one paragraph.
	 * @param int              $menu_order The module number `N`.
	 * @param list<LessonPlan> $lessons    In order.
	 * @param QuizPlan|null    $quiz       «Тест модуля N»; optional only for the last module.
	 */
	public function __construct(
		public string $title,
		public string $html,
		public int $menu_order,
		public array $lessons,
		public ?QuizPlan $quiz
	) {
	}
}
