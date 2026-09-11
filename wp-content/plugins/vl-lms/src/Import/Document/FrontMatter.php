<?php

declare(strict_types=1);

namespace VL\LMS\Import\Document;

/**
 * The parsed frontmatter of a `course.md`: its valid entries in file order.
 *
 * Lines that failed to parse are not here — they are issues. Required keys
 * and allowed values are checked by the validator, not by the parser.
 *
 * @author Tymofii Synianskyi
 */
final readonly class FrontMatter {

	/**
	 * @param list<FrontMatterEntry> $entries
	 */
	public function __construct(
		public array $entries = []
	) {
	}

	public function get( string $key ): ?FrontMatterEntry {
		foreach ( $this->entries as $entry ) {
			if ( $key === $entry->key ) {
				return $entry;
			}
		}

		return null;
	}
}
