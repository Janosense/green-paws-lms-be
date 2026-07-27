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
 * Nameless blocks additionally run through `wpautop()`. `vl_lesson` and
 * `vl_topic` are registered `show_in_rest => false`, so wp-admin serves
 * them the classic editor, which strips `<p>` on save and stores
 * paragraphs as bare double newlines — core restores them at render time
 * via `wpautop()` on `the_content`, a filter this pipeline deliberately
 * never invokes. Without this call the whole post body reaches the
 * frontend as one newline-separated string, and HTML collapses those
 * newlines into single spaces: a structured lesson renders as one wall
 * of text. Named blocks are left alone — their `inner_html` is already
 * well-formed markup, and `wpautop()` would wrap stray text nodes inside
 * it. Sanitize-then-autop mirrors core's own ordering (`wp_kses_post` on
 * save, `wpautop` on render).
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
		$html = wp_kses_post( $block->inner_html );

		return [
			'type' => 'html',
			'html' => '' === $block->name ? wpautop( $html ) : $html,
		];
	}
}
