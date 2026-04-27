<?php

declare(strict_types=1);

namespace VL\LMS\Catalog\Detail;

/**
 * Reshapes the `_vl_webinar_materials` meta into the public materials
 * block on a webinar detail response.
 *
 * The CPT sanitizer at write time normalizes the list to a strict shape
 * (`{ url, name, size }`, URL-required); this transformer is therefore
 * defensive but mostly a passthrough — it filters malformed legacy rows
 * and coerces types, never invents URLs.
 *
 * @author Tymofii Synianskyi
 */
final class MaterialsTransformer {

	/**
	 * @param mixed $raw The raw `get_post_meta(..., '_vl_webinar_materials', true)` value.
	 *
	 * @return list<array{url: string, name: string, size: int}>
	 */
	public function transform( mixed $raw ): array {
		if ( ! is_array( $raw ) ) {
			return [];
		}

		$out = [];
		foreach ( $raw as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$url = isset( $item['url'] ) && is_string( $item['url'] ) ? $item['url'] : '';
			if ( '' === $url ) {
				continue;
			}

			$name = isset( $item['name'] ) && is_string( $item['name'] ) ? $item['name'] : '';
			$size = isset( $item['size'] ) ? (int) $item['size'] : 0;
			if ( $size < 0 ) {
				$size = 0;
			}

			$out[] = [
				'url'  => $url,
				'name' => $name,
				'size' => $size,
			];
		}

		return array_values( $out );
	}
}
