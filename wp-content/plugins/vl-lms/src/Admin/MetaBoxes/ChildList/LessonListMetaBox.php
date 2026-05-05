<?php

declare(strict_types=1);

namespace VL\LMS\Admin\MetaBoxes\ChildList;

/**
 * Sortable lesson list rendered on the `vl_module` edit screen.
 *
 * @author Tymofii Synianskyi
 */
class LessonListMetaBox extends AbstractChildListMetaBox {

	public function __construct() {
		parent::__construct( 'vl_module', 'vl_lesson' );
	}

	public function id(): string {
		return 'vl_lms_lesson_list';
	}

	public function title(): string {
		return 'Уроки';
	}
}
