<?php

declare(strict_types=1);

namespace VL\LMS\Learn\Content\Blocks;

use VL\LMS\Learn\Content\BlockTransformer;
use VL\LMS\Learn\Content\ParsedBlock;

/**
 * Transforms a `core/image` block into `{type: image, url, alt, caption, width, height}`.
 *
 * Source preference: block attributes first, parsed `<img>` tag second.
 * Caption comes from the `<figcaption>` content if present. Dimensions
 * are integer when known, otherwise `null`.
 *
 * @author Tymofii Synianskyi
 */
final class ImageBlockTransformer implements BlockTransformer {

	public function supports( string $block_name ): bool {
		return 'core/image' === $block_name;
	}

	/**
	 * @return array{type:string,url:string,alt:string,caption:?string,width:?int,height:?int}
	 */
	public function transform( ParsedBlock $block ): array {
		$parsed = $this->parse_image( $block->inner_html );

		$url = '';
		if ( isset( $block->attrs['url'] ) && is_string( $block->attrs['url'] ) ) {
			$url = (string) $block->attrs['url'];
		} elseif ( '' !== $parsed['src'] ) {
			$url = $parsed['src'];
		}

		$alt = '';
		if ( isset( $block->attrs['alt'] ) && is_string( $block->attrs['alt'] ) ) {
			$alt = (string) $block->attrs['alt'];
		} elseif ( null !== $parsed['alt'] ) {
			$alt = $parsed['alt'];
		}

		$width  = $this->int_or_null( $block->attrs['width'] ?? null ) ?? $parsed['width'];
		$height = $this->int_or_null( $block->attrs['height'] ?? null ) ?? $parsed['height'];

		$caption = null;
		if ( null !== $parsed['caption'] && '' !== $parsed['caption'] ) {
			$caption = wp_kses_post( $parsed['caption'] );
		}

		return [
			'type'    => 'image',
			'url'     => esc_url_raw( $url ),
			'alt'     => $alt,
			'caption' => $caption,
			'width'   => $width,
			'height'  => $height,
		];
	}

	/**
	 * @return array{src:string,alt:?string,width:?int,height:?int,caption:?string}
	 */
	private function parse_image( string $html ): array {
		$out = [
			'src'     => '',
			'alt'     => null,
			'width'   => null,
			'height'  => null,
			'caption' => null,
		];

		if ( '' === trim( $html ) ) {
			return $out;
		}

		if ( 1 === preg_match( '/<img\b[^>]*?\bsrc=("|\')([^"\']+)\1/i', $html, $m ) ) {
			$out['src'] = $m[2];
		}
		if ( 1 === preg_match( '/<img\b[^>]*?\balt=("|\')([^"\']*)\1/i', $html, $m ) ) {
			$out['alt'] = $m[2];
		}
		if ( 1 === preg_match( '/<img\b[^>]*?\bwidth=("|\')(\d+)\1/i', $html, $m ) ) {
			$out['width'] = (int) $m[2];
		}
		if ( 1 === preg_match( '/<img\b[^>]*?\bheight=("|\')(\d+)\1/i', $html, $m ) ) {
			$out['height'] = (int) $m[2];
		}
		if ( 1 === preg_match( '/<figcaption[^>]*>(.*?)<\/figcaption>/is', $html, $m ) ) {
			$out['caption'] = trim( $m[1] );
		}

		return $out;
	}

	private function int_or_null( mixed $value ): ?int {
		if ( null === $value || '' === $value ) {
			return null;
		}
		if ( is_numeric( $value ) ) {
			return (int) $value;
		}
		return null;
	}
}
