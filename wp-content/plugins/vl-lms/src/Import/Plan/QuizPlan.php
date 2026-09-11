<?php

declare(strict_types=1);

namespace VL\LMS\Import\Plan;

/**
 * A `vl_quiz`: a module quiz or the course's final exam.
 *
 * @author Tymofii Synianskyi
 */
final readonly class QuizPlan {

	/**
	 * @param string             $title         The heading as written: «Тест модуля N» or «Підсумковий тест».
	 * @param bool               $is_final_exam True for «Підсумковий тест» (`_vl_quiz_is_final_exam`).
	 * @param list<QuestionPlan> $questions     In order; never empty.
	 */
	public function __construct(
		public string $title,
		public bool $is_final_exam,
		public array $questions
	) {
	}
}
