<?php

declare(strict_types=1);

namespace VL\LMS\Learn\Content;

/**
 * Thin typed wrapper around WordPress core's `parse_blocks()`.
 *
 * Wraps each top-level block as a {@see ParsedBlock}, recursively wrapping
 * its `innerBlocks` so the whole tree is typed end-to-end. Whitespace-only
 * "freeform" entries that `parse_blocks()` emits for trailing newlines and
 * classic-editor stub content are filtered out.
 *
 * A block is empty when its `name` is empty AND `trim($inner_html)` is
 * empty. Classic-editor content (one big freeform block with real HTML)
 * survives the filter and surfaces as a single nameless `ParsedBlock` —
 * the registry's HTML fallback transformer renders it.
 *
 * @author Tymofii Synianskyi
 */
final class BlockParser {

	/**
	 * @return list<ParsedBlock>
	 */
	public function parse( string $post_content ): array {
		if ( '' === trim( $post_content ) ) {
			return [];
		}

		$raw = parse_blocks( $post_content );
		if ( ! is_array( $raw ) ) {
			return [];
		}

		$out = [];
		foreach ( $raw as $entry ) {
			$wrapped = $this->wrap( $entry );
			if ( null !== $wrapped ) {
				$out[] = $wrapped;
			}
		}
		return $out;
	}

	/**
	 * @param mixed $entry
	 */
	private function wrap( $entry ): ?ParsedBlock {
		if ( ! is_array( $entry ) ) {
			return null;
		}

		$name       = is_string( $entry['blockName'] ?? null ) ? (string) $entry['blockName'] : '';
		$attrs      = is_array( $entry['attrs'] ?? null ) ? $entry['attrs'] : [];
		$inner_html = is_string( $entry['innerHTML'] ?? null ) ? (string) $entry['innerHTML'] : '';

		if ( '' === $name && '' === trim( $inner_html ) ) {
			return null;
		}

		$inner_blocks_raw = is_array( $entry['innerBlocks'] ?? null ) ? $entry['innerBlocks'] : [];
		$inner_blocks     = [];
		foreach ( $inner_blocks_raw as $child ) {
			$wrapped = $this->wrap( $child );
			if ( null !== $wrapped ) {
				$inner_blocks[] = $wrapped;
			}
		}

		$inner_content_raw = is_array( $entry['innerContent'] ?? null ) ? $entry['innerContent'] : [];
		$inner_content     = [];
		foreach ( $inner_content_raw as $piece ) {
			$inner_content[] = is_string( $piece ) ? $piece : null;
		}

		return new ParsedBlock( $name, $attrs, $inner_html, $inner_blocks, $inner_content );
	}
}
