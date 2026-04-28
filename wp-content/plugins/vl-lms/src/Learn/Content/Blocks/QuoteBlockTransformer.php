<?php

declare(strict_types=1);

namespace VL\LMS\Learn\Content\Blocks;

use VL\LMS\Learn\Content\BlockTransformer;
use VL\LMS\Learn\Content\ParsedBlock;

/**
 * Transforms a `core/quote` block into `{type: quote, html, citation}`.
 *
 * Citation is read from the `citation` block attribute when present, else
 * from the `<cite>` tag inside the rendered HTML, else `null`.
 *
 * @author Tymofii Synianskyi
 */
final class QuoteBlockTransformer implements BlockTransformer {

	public function supports( string $block_name ): bool {
		return 'core/quote' === $block_name;
	}

	/**
	 * @return array{type:string,html:string,citation:?string}
	 */
	public function transform( ParsedBlock $block ): array {
		$citation = null;
		if ( isset( $block->attrs['citation'] ) && is_string( $block->attrs['citation'] ) ) {
			$attr = trim( (string) $block->attrs['citation'] );
			if ( '' !== $attr ) {
				$citation = wp_kses_post( $attr );
			}
		}
		if ( null === $citation && 1 === preg_match( '/<cite[^>]*>(.*?)<\/cite>/is', $block->inner_html, $m ) ) {
			$cite = trim( $m[1] );
			if ( '' !== $cite ) {
				$citation = wp_kses_post( $cite );
			}
		}

		return [
			'type'     => 'quote',
			'html'     => wp_kses_post( $block->inner_html ),
			'citation' => $citation,
		];
	}
}
