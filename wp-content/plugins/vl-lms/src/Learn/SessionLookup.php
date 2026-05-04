<?php

declare(strict_types=1);

namespace VL\LMS\Learn;

use WP_Post;
use WP_Query;

/**
 * Resolves a `vl_session` slug to its `WP_Post`.
 *
 * Filters on `post_status = publish`. Same `protected run_query()` test
 * seam as the other Learn / catalog lookups.
 *
 * @author Tymofii Synianskyi
 */
class SessionLookup {

	public function find_by_slug( string $slug ): ?WP_Post {
		if ( '' === $slug ) {
			return null;
		}

		$args = [
			'post_type'              => 'vl_session',
			'name'                   => $slug,
			'post_status'            => 'publish',
			'posts_per_page'         => 1,
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		];

		foreach ( $this->run_query( $args ) as $post ) {
			if ( $post instanceof WP_Post && 'vl_session' === $post->post_type && 'publish' === $post->post_status ) {
				return $post;
			}
		}
		return null;
	}

	/**
	 * @param array<string, mixed> $args
	 * @return list<WP_Post>
	 */
	protected function run_query( array $args ): array {
		$query = new WP_Query( $args );
		$out   = [];
		foreach ( $query->posts as $post ) {
			if ( $post instanceof WP_Post ) {
				$out[] = $post;
			}
		}
		return $out;
	}
}
