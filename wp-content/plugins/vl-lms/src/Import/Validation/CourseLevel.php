<?php

declare(strict_types=1);

namespace VL\LMS\Import\Validation;

/**
 * The frontmatter `level` values of COURSE-FORMAT.md §2 and the `vl_difficulty`
 * term each one maps to (`docs/DECISIONS.md` 2026-09-11 — scope of the
 * importer v1).
 *
 * @author Tymofii Synianskyi
 */
enum CourseLevel: string {

	case BEGINNER     = 'beginner';
	case PRACTITIONER = 'practitioner';
	case ADVANCED     = 'advanced';

	/**
	 * The slug of the `vl_difficulty` term (`docs/DATA-MODEL.md` → Taxonomies).
	 */
	public function difficulty_slug(): string {
		return match ( $this ) {
			self::BEGINNER     => 'basic',
			self::PRACTITIONER => 'advanced',
			self::ADVANCED     => 'expert',
		};
	}
}
