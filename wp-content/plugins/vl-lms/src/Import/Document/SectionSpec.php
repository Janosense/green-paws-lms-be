<?php

declare(strict_types=1);

namespace VL\LMS\Import\Document;

/**
 * One headed section of a `course.md` with its raw Markdown body.
 *
 * `body` is the text between the heading and the next `#`–`###` heading:
 * LF line breaks, HTML comments and `---` rules blanked (so every body line
 * still maps to its file line), outer blank lines trimmed. Body line `k`
 * (0-based) is file line `body_line + k`; an empty body has
 * `body_line = heading_line + 1`.
 *
 * @author Tymofii Synianskyi
 */
final readonly class SectionSpec {

	/**
	 * @param string $heading      The heading text without the `#` markers, e.g. `Зміст`.
	 * @param int    $heading_line 1-based file line of the heading.
	 * @param string $body         Raw Markdown body.
	 * @param int    $body_line    1-based file line of the body's first line.
	 */
	public function __construct(
		public string $heading,
		public int $heading_line,
		public string $body,
		public int $body_line
	) {
	}
}
