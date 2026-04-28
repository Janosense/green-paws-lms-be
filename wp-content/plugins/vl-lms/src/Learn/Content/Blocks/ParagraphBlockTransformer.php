<?php

declare(strict_types=1);

namespace VL\LMS\Learn\Content\Blocks;

use VL\LMS\Learn\Content\BlockTransformer;
use VL\LMS\Learn\Content\ParsedBlock;

/**
 * Transforms a `core/paragraph` block into a `{type: paragraph, html}` shape.
 *
 * The full paragraph HTML (including the `<p>` wrapper Gutenberg emits)
 * is sanitized via `wp_kses_post` before being returned.
 *
 * @author Tymofii Synianskyi
 */
final class ParagraphBlockTransformer implements BlockTransformer {

	public function supports( string $block_name ): bool {
		return 'core/paragraph' === $block_name;
	}

	/**
	 * @return array{type:string,html:string}
	 */
	public function transform( ParsedBlock $block ): array {
		return [
			'type' => 'paragraph',
			'html' => wp_kses_post( $block->inner_html ),
		];
	}
}
