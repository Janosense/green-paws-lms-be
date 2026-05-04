<?php

declare(strict_types=1);

namespace VL\LMS\Services\Webinars;

use WP_Post;
use WP_Query;

/**
 * Resolves a `vl_webinar` slug to its `WP_Post`.
 *
 * Filters on `post_status = publish` — drafts, scheduled, and pending posts
 * are surfaced to the frontend as "not found" rather than an explicit
 * `not_published` (avoiding status leak).
 *
 * Concrete (not final) so unit tests can subclass and override
 * {@see self::run_query()} without booting `WP_Query`. Same seam pattern
 * as {@see \VL\LMS\Services\Zoom\PostLookup}.
 *
 * @author Tymofii Synianskyi
 */
class WebinarLookup {

	public function find_by_slug( string $slug ): ?WP_Post {
		if ( '' === $slug ) {
			return null;
		}

		$args = [
			'post_type'              => 'vl_webinar',
			'name'                   => $slug,
			'post_status'            => 'publish',
			'posts_per_page'         => 1,
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		];

		$posts = $this->run_query( $args );
		foreach ( $posts as $post ) {
			if ( $post instanceof WP_Post && 'vl_webinar' === $post->post_type && 'publish' === $post->post_status ) {
				return $post;
			}
		}
		return null;
	}

	/**
	 * Indirected so unit tests can subclass and override without booting
	 * `WP_Query`.
	 *
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
