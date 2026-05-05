<?php

declare(strict_types=1);

namespace VL\LMS\Admin\MetaBoxes\ChildList;

/**
 * Sortable question list rendered on the `vl_quiz` edit screen.
 *
 * @author Tymofii Synianskyi
 */
class QuestionListMetaBox extends AbstractChildListMetaBox {

	public function __construct() {
		parent::__construct( 'vl_quiz', 'vl_quiz_question' );
	}

	public function id(): string {
		return 'vl_lms_question_list';
	}

	public function title(): string {
		return 'Питання';
	}
}
