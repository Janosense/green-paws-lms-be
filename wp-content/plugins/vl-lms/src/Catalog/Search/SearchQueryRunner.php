<?php

declare(strict_types=1);

namespace VL\LMS\Catalog\Search;

use WP_Post;
use WP_Query;

/**
 * Thin wrapper around `WP_Query` for search sections.
 *
 * Centralizes the one place we instantiate `WP_Query` for search so the
 * controller stays query-runner-agnostic and unit tests can substitute
 * a fake without requiring the WordPress runtime. Mirrors the
 * {@see \VL\LMS\Catalog\Detail\PostFinder} pattern used by detail
 * transformers, but exposes pagination metadata since list-style
 * responses surface `total` / `total_pages`.
 *
 * Binds the {@see RelevanceRanker} to the freshly-built query for the
 * duration of the run, and tears it down regardless of how the query
 * exits. The ranker's own `release` is idempotent, so the `finally`
 * call here is safe even when the `posts_results` hook already cleared
 * the binding.
 *
 * @author Tymofii Synianskyi
 */
class SearchQueryRunner {

	/**
	 * @param array<string, mixed> $args
	 *
	 * @return array{posts: list<WP_Post>, total: int, total_pages: int}
	 */
	public function run( array $args, RelevanceRanker $ranker, string $q ): array {
		$query = new WP_Query();

		$ranker->bind( $query, $q );
		try {
			$query->query( $args );
		} finally {
			$ranker->release();
		}

		$out = [];
		foreach ( (array) $query->posts as $post ) {
			if ( $post instanceof WP_Post ) {
				$out[] = $post;
			}
		}

		return [
			'posts'       => $out,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
		];
	}
}
