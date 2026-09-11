<?php

declare(strict_types=1);

namespace VL\LMS\Import\Issue;

/**
 * Severity of an {@see ImportIssue}.
 *
 * An error blocks the import and nothing is created; a warning is listed in
 * the preview and the report but never blocks; a note (`info`) only tells the
 * admin what the importer leaves out on purpose, such as «Структура курсу»
 * (course-import FEATURE.md → Invariants).
 *
 * @author Tymofii Synianskyi
 */
enum IssueLevel: string {

	case ERROR   = 'error';
	case WARNING = 'warning';
	case INFO    = 'info';
}
