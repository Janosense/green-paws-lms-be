<?php

declare(strict_types=1);

namespace VL\LMS\Admin\MetaBoxes\ChildList;

/**
 * Sortable question list rendered on the `vl_quiz` edit screen.
 *
 * Questions are hidden from the wp-admin menu (`vl_quiz_question` has
 * `show_in_menu: false`), so the add / unlink picker from
 * {@see AbstractPickerListMetaBox} is the primary way to build and manage
 * a quiz's questions: a "Додати питання" button (the new question is
 * pre-bound to this quiz by {@see \VL\LMS\Admin\MetaBoxes\QuizQuestionMetaBox}
 * via the `?vl_parent_id=` hint), an existing-question search that
 * re-parents an unattached question to this quiz, and a per-row
 * "Відкріпити" detach ({@see \VL\LMS\Admin\Questions\QuestionPickerAjaxHandler}).
 *
 * @author Tymofii Synianskyi
 */
class QuestionListMetaBox extends AbstractPickerListMetaBox {

	public function __construct() {
		parent::__construct( 'vl_quiz', 'vl_quiz_question', 'question' );
	}

	public function id(): string {
		return 'vl_lms_question_list';
	}

	public function title(): string {
		return 'Питання';
	}

	protected function add_new_label(): string {
		return (string) __( 'Додати питання', 'vl-lms' );
	}

	protected function search_label(): string {
		return (string) __( 'Або обрати існуюче питання', 'vl-lms' );
	}

	protected function search_placeholder(): string {
		return (string) __( 'Текст питання', 'vl-lms' );
	}
}
