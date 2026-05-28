<?php

declare(strict_types=1);

namespace VL\LMS\Admin\MetaBoxes\ChildList;

/**
 * Shared base for the `vl_lesson` list boxes — the course's **Уроки
 * курсу** ({@see CourseLessonListMetaBox}) and the module's **Уроки**
 * ({@see LessonListMetaBox}). Both render the same add / unlink picker;
 * the only difference is the bound parent post type.
 *
 * The lesson-specific labels live here; the entity-agnostic markup and
 * the `vl_lms_lesson_{search,attach,detach}` wiring come from
 * {@see AbstractPickerListMetaBox} ({@see \VL\LMS\Admin\Lessons\LessonPickerAjaxHandler}).
 *
 * @author Tymofii Synianskyi
 */
abstract class AbstractLessonListMetaBox extends AbstractPickerListMetaBox {

	public function __construct( string $parent_type ) {
		parent::__construct( $parent_type, 'vl_lesson', 'lesson' );
	}

	protected function add_new_label(): string {
		return (string) __( 'Додати урок', 'vl-lms' );
	}

	protected function search_label(): string {
		return (string) __( 'Або обрати існуючий урок', 'vl-lms' );
	}

	protected function search_placeholder(): string {
		return (string) __( 'Назва уроку', 'vl-lms' );
	}
}
