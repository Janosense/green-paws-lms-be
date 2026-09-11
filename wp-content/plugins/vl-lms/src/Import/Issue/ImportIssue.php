<?php

declare(strict_types=1);

namespace VL\LMS\Import\Issue;

/**
 * One finding about an uploaded `course.md`: a format error or a warning.
 *
 * `code` is a stable machine name (`frontmatter.invalid_value`,
 * `structure.unknown_heading` …) declared as a constant on the class that
 * emits it; tests assert codes, never messages. `line` is the 1-based line in
 * the uploaded file, or null when the issue has no position. `message` is the
 * Ukrainian text the admin reads — it never repeats the line number, the
 * preview shows that in its own column.
 *
 * @author Tymofii Synianskyi
 */
final readonly class ImportIssue {

	public function __construct(
		public IssueLevel $level,
		public string $code,
		public ?int $line,
		public string $message
	) {
	}

	public static function error( string $code, ?int $line, string $message ): self {
		return new self( IssueLevel::ERROR, $code, $line, $message );
	}

	public static function warning( string $code, ?int $line, string $message ): self {
		return new self( IssueLevel::WARNING, $code, $line, $message );
	}
}
