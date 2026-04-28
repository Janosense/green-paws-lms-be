<?php

declare(strict_types=1);

namespace VL\LMS\Catalog\Search;

use WP_Query;

/**
 * Layers relevance-aware ordering on top of a single `WP_Query` search.
 *
 * Default WP `s` searches rank by `post_date DESC`, which is wrong for a
 * cross-type catalog search where a user typing "cardiology" expects the
 * post with that term in the title to outrank a post that only mentions
 * it in the body. This ranker adjusts ordering so that posts whose title
 * matches the query are returned first; ties fall back to date.
 *
 * Implementation notes:
 *
 * - The ranker is scoped to a specific {@see WP_Query} instance via
 *   identity comparison inside the filter callbacks. Other queries that
 *   run during the same request (nav menus, sidebar widgets) are
 *   untouched.
 * - All SQL fragments injected through `posts_orderby` use `$wpdb->prepare()`.
 *   The user-supplied query string is escaped via `esc_sql` before it
 *   goes anywhere near the LIKE pattern.
 * - Filters are unregistered as soon as the query is run (via the
 *   `posts_results` hook) so callbacks never leak across requests in
 *   long-running PHP-FPM processes.
 *
 * @author Tymofii Synianskyi
 */
final class RelevanceRanker {

	private ?WP_Query $bound_query = null;

	private string $bound_query_term = '';

	/**
	 * Bind the ranker to a query instance. Subsequent filter callbacks
	 * only modify SQL that was generated for this exact query.
	 */
	public function bind( WP_Query $query, string $query_term ): void {
		$this->bound_query      = $query;
		$this->bound_query_term = $query_term;

		add_filter( 'posts_orderby', [ $this, 'filter_orderby' ], 10, 2 );
		add_filter( 'posts_results', [ $this, 'release_after_run' ], 10, 2 );
	}

	/**
	 * Replace the default `posts_orderby` with a CASE-based title-priority
	 * ordering. Title matches sort first; ties fall back to `post_date DESC`.
	 *
	 * @param string $orderby The SQL fragment WP would have used.
	 *
	 * @return string The replacement fragment, or the original when this
	 *                callback fired for a query the ranker did not bind to.
	 */
	public function filter_orderby( string $orderby, WP_Query $query ): string {
		if ( $query !== $this->bound_query ) {
			return $orderby;
		}

		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return $orderby;
		}

		$like = '%' . $wpdb->esc_like( $this->bound_query_term ) . '%';

		// Phase 3.5: title matches outrank content-only matches; ties by date.
		// `$wpdb->prepare` enforces parameter binding for the LIKE pattern.
		$prepared = $wpdb->prepare(
			"CASE WHEN {$wpdb->posts}.post_title LIKE %s THEN 0 ELSE 1 END ASC, {$wpdb->posts}.post_date DESC",
			$like
		);

		return is_string( $prepared ) ? $prepared : $orderby;
	}

	/**
	 * Tear down the filters as soon as the bound query has run.
	 *
	 * @param array<int, \WP_Post> $posts
	 *
	 * @return array<int, \WP_Post>
	 */
	public function release_after_run( array $posts, WP_Query $query ): array {
		if ( $query === $this->bound_query ) {
			$this->release();
		}
		return $posts;
	}

	/**
	 * Unregister filters and clear the binding. Safe to call multiple times.
	 */
	public function release(): void {
		remove_filter( 'posts_orderby', [ $this, 'filter_orderby' ], 10 );
		remove_filter( 'posts_results', [ $this, 'release_after_run' ], 10 );
		$this->bound_query      = null;
		$this->bound_query_term = '';
	}
}
