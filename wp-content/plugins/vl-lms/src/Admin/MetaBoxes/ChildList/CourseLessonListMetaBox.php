<?php

declare(strict_types=1);

namespace VL\LMS\Admin\MetaBoxes\ChildList;

/**
 * Sortable course-direct lesson list rendered on the `vl_course` edit
 * screen.
 *
 * Course-direct lessons are `vl_lesson` posts parented to the course
 * itself rather than to a module
 * ({@see \VL\LMS\Learn\CurriculumTransformer::query_orphan_lessons()}).
 * Distinct from {@see LessonListMetaBox}, which lists a module's lessons;
 * both share the add / unlink picker from {@see AbstractLessonListMetaBox}.
 *
 * @author Tymofii Synianskyi
 */
class CourseLessonListMetaBox extends AbstractLessonListMetaBox {

	public function __construct() {
		parent::__construct( 'vl_course' );
	}

	public function id(): string {
		return 'vl_lms_course_lesson_list';
	}

	public function title(): string {
		return 'Уроки курсу';
	}
}
