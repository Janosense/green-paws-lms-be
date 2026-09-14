<?php

declare(strict_types=1);

namespace VL\LMS\Import\Plan;

/**
 * A `vl_quiz_question` with its answers. Texts are kept as written: the
 * format allows inline Markdown only inside «Зміст», and the question is
 * stored as a post title and plain answer texts.
 *
 * @author Tymofii Synianskyi
 */
final readonly class QuestionPlan {

	/**
	 * `_vl_question_type` values (`docs/DATA-MODEL.md` → `vl_quiz_question`).
	 */
	public const SINGLE_CHOICE   = 'single_choice';
	public const MULTIPLE_CHOICE = 'multiple_choice';

	/**
	 * @param string                                      $text        The question without its number.
	 * @param int                                         $menu_order  The question number `N`.
	 * @param string                                      $type        {@see self::SINGLE_CHOICE} or {@see self::MULTIPLE_CHOICE}.
	 * @param list<array{text: string, is_correct: bool}> $answers     In order.
	 * @param string|null                                 $explanation The `> Пояснення:` text.
	 */
	public function __construct(
		public string $text,
		public int $menu_order,
		public string $type,
		public array $answers,
		public ?string $explanation
	) {
	}
}
