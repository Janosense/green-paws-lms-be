<?php

declare(strict_types=1);

namespace VL\LMS\Admin\MetaBoxes\ChildList;

/**
 * Sortable lesson list rendered on the `vl_module` edit screen.
 *
 * Shares the add / unlink picker with the course screen via
 * {@see AbstractLessonListMetaBox}: a "Додати урок" button, an
 * existing-lesson search that re-parents an unattached lesson to this
 * module, and a per-row "Відкріпити" detach.
 *
 * @author Tymofii Synianskyi
 */
class LessonListMetaBox extends AbstractLessonListMetaBox {

	public function __construct() {
		parent::__construct( 'vl_module' );
	}

	public function id(): string {
		return 'vl_lms_lesson_list';
	}

	public function title(): string {
		return 'Уроки';
	}
}
