<?php

declare(strict_types=1);

namespace VL\LMS\Admin\MetaBoxes\ChildList;

/**
 * Sortable assignment list rendered on a curriculum parent edit screen.
 *
 * Assignments attach flexibly under a `vl_course`, `vl_module`, `vl_lesson`,
 * or `vl_session` (see {@see \VL\LMS\CPT\AssignmentType}), so this box is
 * registered once per parent type — the constructor takes the parent CPT
 * slug. It is the parent-side counterpart to the assignment edit screen's
 * own {@see \VL\LMS\Admin\MetaBoxes\CurriculumParentPickerTrait} picker:
 *
 *  - "Додати завдання" →
 *    `post-new.php?post_type=vl_assignment&vl_parent_id=<parent>`,
 *  - an existing-assignment search that re-parents an unattached assignment,
 *  - a per-row "Відкріпити" detach
 *    ({@see \VL\LMS\Admin\Assignments\AssignmentPickerAjaxHandler}).
 *
 * @author Tymofii Synianskyi
 */
class AssignmentListMetaBox extends AbstractPickerListMetaBox {

	public function __construct( string $parent_type ) {
		parent::__construct( $parent_type, 'vl_assignment', 'assignment' );
	}

	public function id(): string {
		return 'vl_lms_assignment_list';
	}

	public function title(): string {
		return 'Завдання';
	}

	protected function add_new_label(): string {
		return (string) __( 'Додати завдання', 'vl-lms' );
	}

	protected function search_label(): string {
		return (string) __( 'Або обрати існуюче завдання', 'vl-lms' );
	}

	protected function search_placeholder(): string {
		return (string) __( 'Назва завдання', 'vl-lms' );
	}
}
