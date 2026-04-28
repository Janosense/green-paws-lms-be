<?php

declare(strict_types=1);

namespace VL\LMS\Learn\Content\Blocks;

use VL\LMS\Learn\Content\BlockTransformer;
use VL\LMS\Learn\Content\ParsedBlock;

/**
 * Transforms a `core/file` block into `{type: file, url, name, size}`.
 *
 * `size` is resolved through `wp_get_attachment_metadata()` when the
 * block carries the source attachment's `id`. A miss (no metadata or no
 * `filesize` key) leaves `size` `null`.
 *
 * @author Tymofii Synianskyi
 */
final class FileBlockTransformer implements BlockTransformer {

	public function supports( string $block_name ): bool {
		return 'core/file' === $block_name;
	}

	/**
	 * @return array{type:string,url:string,name:string,size:?int}
	 */
	public function transform( ParsedBlock $block ): array {
		$url  = isset( $block->attrs['href'] ) && is_string( $block->attrs['href'] )
			? (string) $block->attrs['href']
			: ( isset( $block->attrs['url'] ) && is_string( $block->attrs['url'] ) ? (string) $block->attrs['url'] : '' );
		$name = isset( $block->attrs['fileName'] ) && is_string( $block->attrs['fileName'] )
			? (string) $block->attrs['fileName']
			: ( isset( $block->attrs['name'] ) && is_string( $block->attrs['name'] ) ? (string) $block->attrs['name'] : '' );

		$size = null;
		if ( isset( $block->attrs['id'] ) && is_numeric( $block->attrs['id'] ) ) {
			$size = $this->resolve_attachment_size( (int) $block->attrs['id'] );
		}

		return [
			'type' => 'file',
			'url'  => esc_url_raw( $url ),
			'name' => wp_kses_post( $name ),
			'size' => $size,
		];
	}

	private function resolve_attachment_size( int $attachment_id ): ?int {
		$meta = wp_get_attachment_metadata( $attachment_id );
		if ( ! is_array( $meta ) ) {
			return null;
		}
		// The phpstan-wordpress stub types `filesize` as a guaranteed
		// `int`, but in production it is missing for image attachments
		// (and any attachment whose MIME handler did not set it). The
		// `array_key_exists` guard reflects the runtime reality without
		// triggering the always-true sniff a `??` chain would.
		if ( ! array_key_exists( 'filesize', $meta ) ) {
			return null;
		}
		$value = $meta['filesize'];
		if ( ! is_numeric( $value ) ) {
			return null;
		}
		return (int) $value;
	}
}
