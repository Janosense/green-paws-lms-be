<?php

declare(strict_types=1);

namespace VL\LMS\Admin\MetaBoxes\ChildList;

/**
 * Sortable session list rendered on the `vl_course` edit screen.
 *
 * Cohort courses parent their `vl_session` posts directly under the
 * course. This box mirrors {@see ModuleListMetaBox} for the cohort arm;
 * `admin-meta-boxes.js` toggles the two boxes based on `_vl_course_type`
 * so only the relevant one shows.
 *
 * @author Tymofii Synianskyi
 */
class SessionListMetaBox extends AbstractChildListMetaBox {

	public function __construct() {
		parent::__construct( 'vl_course', 'vl_session' );
	}

	public function id(): string {
		return 'vl_lms_session_list';
	}

	public function title(): string {
		return 'Сесії';
	}
}
