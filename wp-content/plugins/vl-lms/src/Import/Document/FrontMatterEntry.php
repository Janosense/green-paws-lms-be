<?php

declare(strict_types=1);

namespace VL\LMS\Import\Document;

/**
 * One `key: value` line of the course frontmatter.
 *
 * @author Tymofii Synianskyi
 */
final readonly class FrontMatterEntry {

	/**
	 * @param string|int|list<string> $value A string for `string` and `date`, an int for `int`, a list of strings for `list`.
	 * @param int                     $line  1-based line in the uploaded file.
	 */
	public function __construct(
		public string $key,
		public string|int|array $value,
		public FrontMatterType $type,
		public int $line
	) {
	}
}
