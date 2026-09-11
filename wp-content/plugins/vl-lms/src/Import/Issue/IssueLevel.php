<?php

declare(strict_types=1);

namespace VL\LMS\Import\Issue;

/**
 * Severity of an {@see ImportIssue}.
 *
 * An error blocks the import and nothing is created; a warning is listed in
 * the preview and the report but never blocks (course-import FEATURE.md →
 * Invariants).
 *
 * @author Tymofii Synianskyi
 */
enum IssueLevel: string {

	case ERROR   = 'error';
	case WARNING = 'warning';
}
