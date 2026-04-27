<?php

declare(strict_types=1);

namespace VL\LMS\Catalog\Detail;

use WP_Post;
use WP_Query;

/**
 * Thin wrapper around `WP_Query` used by detail transformers.
 *
 * Centralizes the one place we instantiate `WP_Query` so the transformers
 * stay query-runner-agnostic and unit tests can substitute a fake without
 * requiring the WordPress runtime to be loaded.
 *
 * Always returns a `list<WP_Post>`. Pagination metadata is irrelevant for
 * detail responses (curriculum is fetched whole) so this wrapper does not
 * surface `found_posts` / `max_num_pages`.
 *
 * @author Tymofii Synianskyi
 */
class PostFinder {

	/**
	 * @param array<string, mixed> $args
	 *
	 * @return list<WP_Post>
	 */
	public function find( array $args ): array {
		$query = new WP_Query( $args );
		$posts = $query->posts;
		if ( ! is_array( $posts ) ) {
			return [];
		}

		$out = [];
		foreach ( $posts as $post ) {
			if ( $post instanceof WP_Post ) {
				$out[] = $post;
			}
		}
		return $out;
	}
}
