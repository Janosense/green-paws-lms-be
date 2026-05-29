<?php

declare(strict_types=1);

namespace VL\LMS\Admin\MetaBoxes\ChildList;

/**
 * Sortable quiz list rendered on a curriculum parent edit screen.
 *
 * Quizzes attach flexibly under a `vl_course`, `vl_module`, `vl_lesson`, or
 * `vl_session` (see {@see \VL\LMS\CPT\QuizType}), so this box is registered
 * once per parent type — the constructor takes the parent CPT slug. It is
 * the parent-side counterpart to the quiz edit screen's own
 * {@see \VL\LMS\Admin\MetaBoxes\CurriculumParentPickerTrait} picker:
 *
 *  - "Додати тест" → `post-new.php?post_type=vl_quiz&vl_parent_id=<parent>`
 *    (the new quiz's parent is pre-selected by its picker),
 *  - an existing-quiz search that re-parents an unattached quiz, and
 *  - a per-row "Відкріпити" detach
 *    ({@see \VL\LMS\Admin\Quizzes\QuizPickerAjaxHandler}).
 *
 * @author Tymofii Synianskyi
 */
class QuizListMetaBox extends AbstractPickerListMetaBox {

	public function __construct( string $parent_type ) {
		parent::__construct( $parent_type, 'vl_quiz', 'quiz' );
	}

	public function id(): string {
		return 'vl_lms_quiz_list';
	}

	public function title(): string {
		return 'Тести';
	}

	protected function add_new_label(): string {
		return (string) __( 'Додати тест', 'vl-lms' );
	}

	protected function search_label(): string {
		return (string) __( 'Або обрати існуючий тест', 'vl-lms' );
	}

	protected function search_placeholder(): string {
		return (string) __( 'Назва тесту', 'vl-lms' );
	}
}
