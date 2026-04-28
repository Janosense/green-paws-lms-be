<?php

declare(strict_types=1);

namespace VL\LMS\Learn\Content\Blocks;

use VL\LMS\Learn\Content\BlockTransformer;
use VL\LMS\Learn\Content\ParsedBlock;

/**
 * Transforms a `core/list` block into `{type: list, ordered, items}`.
 *
 * Only direct `core/list-item` children at the top level are walked. Each
 * item's `inner_html` becomes one `items[]` entry after `wp_kses_post`.
 * Nested lists inside an item survive as inline `<ul>` / `<ol>` markup
 * inside the item HTML — no recursive structuring in v1.
 *
 * @author Tymofii Synianskyi
 */
final class ListBlockTransformer implements BlockTransformer {

	public function supports( string $block_name ): bool {
		return 'core/list' === $block_name;
	}

	/**
	 * @return array{type:string,ordered:bool,items:list<string>}
	 */
	public function transform( ParsedBlock $block ): array {
		$ordered = (bool) ( $block->attrs['ordered'] ?? false );

		$items = [];
		foreach ( $block->inner_blocks as $child ) {
			if ( 'core/list-item' !== $child->name ) {
				continue;
			}
			$items[] = wp_kses_post( $child->inner_html );
		}

		return [
			'type'    => 'list',
			'ordered' => $ordered,
			'items'   => $items,
		];
	}
}
