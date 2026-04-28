<?php

declare(strict_types=1);

namespace VL\LMS\Learn\Content\Blocks;

use VL\LMS\Learn\Content\BlockTransformer;
use VL\LMS\Learn\Content\ParsedBlock;

/**
 * Transforms a `core/heading` block into a `{type: heading, level, text, anchor}` shape.
 *
 * Heading level is clamped to the 2–6 range — level 1 belongs to the page
 * `<h1>` (course/lesson title) and any level beyond 6 has no semantic
 * mapping. Anchor preserves the heading's `id` attribute when it parses
 * as a slug-safe string, otherwise `null`.
 *
 * @author Tymofii Synianskyi
 */
final class HeadingBlockTransformer implements BlockTransformer {

	public function supports( string $block_name ): bool {
		return 'core/heading' === $block_name;
	}

	/**
	 * @return array{type:string,level:int,text:string,anchor:?string}
	 */
	public function transform( ParsedBlock $block ): array {
		$attr_level = isset( $block->attrs['level'] ) ? (int) $block->attrs['level'] : 2;
		$level      = max( 2, min( 6, $attr_level ) );

		$text = trim( wp_strip_all_tags( $block->inner_html ) );

		$anchor = null;
		if ( isset( $block->attrs['anchor'] ) && is_string( $block->attrs['anchor'] ) ) {
			$candidate = (string) $block->attrs['anchor'];
			if ( 1 === preg_match( '/^[A-Za-z0-9_\-]+$/', $candidate ) ) {
				$anchor = $candidate;
			}
		}

		return [
			'type'   => 'heading',
			'level'  => $level,
			'text'   => $text,
			'anchor' => $anchor,
		];
	}
}
