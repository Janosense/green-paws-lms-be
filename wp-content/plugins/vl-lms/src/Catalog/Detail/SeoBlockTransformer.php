<?php

declare(strict_types=1);

namespace VL\LMS\Catalog\Detail;

use VL\LMS\Catalog\PostType;
use VL\LMS\Support\PlainText;
use WP_Post;

/**
 * Builds the `seo: { title, description, og_image, canonical_path }`
 * block on course / webinar detail responses.
 *
 * Inputs come pre-resolved from the calling transformer:
 *
 *   - `$post` — the underlying WP_Post (used for the title).
 *   - `$post_type` — the `PostType` enum, drives the canonical path.
 *   - `$excerpt` — the same string already used as `excerpt` in the
 *     payload, truncated to 160 chars at a word boundary.
 *   - `$cover` — the cover map returned by
 *     {@see \VL\LMS\Catalog\Transformers\CoverImageTransformer}, used
 *     to pick `og_image` (hero → full → card → null).
 *
 * The site-name suffix (` | Green Paws LMS`) is hard-coded in 3.2; a
 * config helper will replace it once we settle on a configuration story
 * for site identity.
 *
 * @author Tymofii Synianskyi
 */
final class SeoBlockTransformer {

	private const TITLE_SUFFIX             = ' | Green Paws LMS';
	private const DESCRIPTION_MAX_LENGTH   = 160;
	private const OG_IMAGE_SIZE_PRECEDENCE = [ 'hero', 'full', 'card' ];

	/**
	 * @param array<string, array{url: string, width: int, height: int}>|null $cover
	 *
	 * @return array{
	 *     title: string,
	 *     description: string,
	 *     og_image: string|null,
	 *     canonical_path: string
	 * }
	 */
	public function transform(
		WP_Post $post,
		PostType $post_type,
		string $excerpt,
		?array $cover
	): array {
		$slug = (string) $post->post_name;

		return [
			'title'          => $this->build_title( $post ),
			'description'    => $this->truncate_description( $excerpt ),
			'og_image'       => $this->pick_og_image( $cover ),
			'canonical_path' => $post_type->permalink_prefix() . $slug,
		];
	}

	private function build_title( WP_Post $post ): string {
		return PlainText::from_html( (string) get_the_title( $post ) ) . self::TITLE_SUFFIX;
	}

	private function truncate_description( string $excerpt ): string {
		$trimmed = trim( $excerpt );
		if ( '' === $trimmed ) {
			return '';
		}
		if ( mb_strlen( $trimmed ) <= self::DESCRIPTION_MAX_LENGTH ) {
			return $trimmed;
		}

		// Truncate at a word boundary inside the budget; fall back to a
		// hard cut when there's no whitespace in the head.
		$head     = mb_substr( $trimmed, 0, self::DESCRIPTION_MAX_LENGTH );
		$last_sep = max(
			mb_strrpos( $head, ' ' ) === false ? -1 : (int) mb_strrpos( $head, ' ' ),
			mb_strrpos( $head, "\t" ) === false ? -1 : (int) mb_strrpos( $head, "\t" ),
			mb_strrpos( $head, "\n" ) === false ? -1 : (int) mb_strrpos( $head, "\n" )
		);
		if ( $last_sep > 0 ) {
			return rtrim( mb_substr( $head, 0, $last_sep ) ) . '…';
		}
		return $head . '…';
	}

	/**
	 * @param array<string, array{url: string, width: int, height: int}>|null $cover
	 */
	private function pick_og_image( ?array $cover ): ?string {
		if ( null === $cover ) {
			return null;
		}
		foreach ( self::OG_IMAGE_SIZE_PRECEDENCE as $key ) {
			if ( isset( $cover[ $key ]['url'] ) && '' !== $cover[ $key ]['url'] ) {
				return $cover[ $key ]['url'];
			}
		}
		return null;
	}
}
