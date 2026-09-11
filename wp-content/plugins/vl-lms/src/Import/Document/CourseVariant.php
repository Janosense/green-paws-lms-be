<?php

declare(strict_types=1);

namespace VL\LMS\Import\Document;

/**
 * The two structures COURSE-FORMAT.md §4 allows: modules with `## Урок N.M.`
 * lessons, or a flat course with `## Урок N.` lessons. One file uses one.
 *
 * @author Tymofii Synianskyi
 */
enum CourseVariant: string {

	case MODULES = 'modules';
	case FLAT    = 'flat';
}
