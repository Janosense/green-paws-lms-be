<?php

declare(strict_types=1);

namespace VL\LMS\Catalog\Transformers;

/**
 * Builds the `cover` payload for a catalog card or detail page from a
 * cover image attachment ID.
 *
 * Emits up to four sizes — `thumbnail` (WP `thumbnail`), `card` (WP
 * `medium_large`), `hero` (WP `vl_hero`, the 1920×720 banner registered
 * in {@see \VL\LMS\Support\HeroImageSize}), `full` (WP `full`). Returns
 * `null` for `0` or for an attachment that no longer exists, and omits
 * any specific size that isn't available on the file rather than
 * fabricating URLs. The `hero` size is missing on installs that
 * pre-date Phase 3.2 until the admin regenerates thumbnails.
 *
 * @author Tymofii Synianskyi
 */
final class CoverImageTransformer {

	private const SIZE_MAP = [
		'thumbnail' => 'thumbnail',
		'card'      => 'medium_large',
		'hero'      => 'vl_hero',
		'full'      => 'full',
	];

	/**
	 * @return array{
	 *     thumbnail?: array{url: string, width: int, height: int},
	 *     card?:      array{url: string, width: int, height: int},
	 *     hero?:      array{url: string, width: int, height: int},
	 *     full?:      array{url: string, width: int, height: int}
	 * }|null
	 */
	public function transform( int $attachment_id ): ?array {
		if ( $attachment_id <= 0 ) {
			return null;
		}

		$post = get_post( $attachment_id );
		if ( null === $post ) {
			return null;
		}

		$out = [];
		$any = false;
		foreach ( self::SIZE_MAP as $key => $wp_size ) {
			$src = wp_get_attachment_image_src( $attachment_id, $wp_size );
			if ( ! is_array( $src ) || '' === (string) $src[0] ) {
				continue;
			}
			$out[ $key ] = [
				'url'    => (string) $src[0],
				'width'  => (int) $src[1],
				'height' => (int) $src[2],
			];
			$any         = true;
		}

		return $any ? $out : null;
	}
}
