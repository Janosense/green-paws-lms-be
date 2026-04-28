<?php

declare(strict_types=1);

namespace VL\LMS\Learn\Content;

/**
 * Immutable representation of one Gutenberg block produced by
 * {@see BlockParser::parse()}.
 *
 * Mirrors the shape returned by core's `parse_blocks()` but typed and
 * recursively wrapped — `inner_blocks` is itself a `ParsedBlock[]` so
 * downstream transformers can walk children without re-parsing raw arrays.
 *
 * @author Tymofii Synianskyi
 */
final class ParsedBlock {

	/**
	 * @param array<string, mixed>     $attrs         Block attributes from `parse_blocks()`.
	 * @param list<self>               $inner_blocks  Recursively wrapped children.
	 * @param list<string|null>        $inner_content Mixed array from `parse_blocks()` — string fragments interleaved with `null` markers for child block positions.
	 */
	public function __construct(
		public readonly string $name,
		public readonly array $attrs,
		public readonly string $inner_html,
		public readonly array $inner_blocks,
		public readonly array $inner_content
	) {
	}
}
