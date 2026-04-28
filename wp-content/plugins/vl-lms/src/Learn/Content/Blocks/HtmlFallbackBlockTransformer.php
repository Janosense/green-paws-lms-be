<?php

declare(strict_types=1);

namespace VL\LMS\Learn\Content\Blocks;

use VL\LMS\Learn\Content\BlockTransformer;
use VL\LMS\Learn\Content\ParsedBlock;

/**
 * Catch-all transformer.
 *
 * Reports `supports()` as `true` for any block name so the registry can
 * place it last in the dispatch list and never throw on an unknown block.
 * The output is the block's `inner_html` passed through `wp_kses_post`,
 * preventing raw user-authored markup from reaching the API response.
 *
 * @author Tymofii Synianskyi
 */
final class HtmlFallbackBlockTransformer implements BlockTransformer {

	public function supports( string $block_name ): bool {
		return true;
	}

	/**
	 * @return array{type:string,html:string}
	 */
	public function transform( ParsedBlock $block ): array {
		return [
			'type' => 'html',
			'html' => wp_kses_post( $block->inner_html ),
		];
	}
}
