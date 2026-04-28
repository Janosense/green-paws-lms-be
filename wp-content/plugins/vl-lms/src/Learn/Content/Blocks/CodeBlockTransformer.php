<?php

declare(strict_types=1);

namespace VL\LMS\Learn\Content\Blocks;

use VL\LMS\Learn\Content\BlockTransformer;
use VL\LMS\Learn\Content\ParsedBlock;

/**
 * Transforms a `core/code` block into `{type: code, code, language}`.
 *
 * The code body is the inner `<code>` text content with HTML entities
 * decoded so client-side highlighters receive raw source. Language is
 * extracted from a `language-*` token in the `className` attribute when
 * present; otherwise `null`.
 *
 * @author Tymofii Synianskyi
 */
final class CodeBlockTransformer implements BlockTransformer {

	public function supports( string $block_name ): bool {
		return 'core/code' === $block_name;
	}

	/**
	 * @return array{type:string,code:string,language:?string}
	 */
	public function transform( ParsedBlock $block ): array {
		$code = $block->inner_html;
		if ( 1 === preg_match( '/<code[^>]*>(.*?)<\/code>/is', $block->inner_html, $m ) ) {
			$code = $m[1];
		}
		$code = html_entity_decode( wp_strip_all_tags( $code ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		$language = null;
		$class    = isset( $block->attrs['className'] ) && is_string( $block->attrs['className'] )
			? (string) $block->attrs['className']
			: '';
		if ( '' !== $class && 1 === preg_match( '/(?:^|\s)language-([A-Za-z0-9_+\-]+)/', $class, $m ) ) {
			$language = $m[1];
		}

		return [
			'type'     => 'code',
			'code'     => $code,
			'language' => $language,
		];
	}
}
