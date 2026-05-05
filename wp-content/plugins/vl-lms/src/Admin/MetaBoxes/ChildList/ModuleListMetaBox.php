<?php

declare(strict_types=1);

namespace VL\LMS\Admin\MetaBoxes\ChildList;

/**
 * Sortable module list rendered on the `vl_course` edit screen.
 *
 * @author Tymofii Synianskyi
 */
class ModuleListMetaBox extends AbstractChildListMetaBox {

	public function __construct() {
		parent::__construct( 'vl_course', 'vl_module' );
	}

	public function id(): string {
		return 'vl_lms_module_list';
	}

	public function title(): string {
		return 'Модулі';
	}
}
