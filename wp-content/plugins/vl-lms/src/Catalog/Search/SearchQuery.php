<?php

declare(strict_types=1);

namespace VL\LMS\Catalog\Search;

use VL\LMS\Catalog\PostType;

/**
 * Builds the `WP_Query` argument array for a single CPT inside a search
 * request.
 *
 * Search-only by design — does not reuse {@see \VL\LMS\Catalog\CatalogQuery}
 * because that class encodes filter/sort concerns (taxonomies, upcoming
 * meta-query) the search endpoint does not need. Forking risks coupling;
 * a dedicated, narrower builder is clearer.
 *
 * The resulting args are intentionally close to a literal dump so tests
 * can assert structure without booting the WP query layer. Relevance
 * ranking is layered on top by {@see RelevanceRanker} via filters; this
 * class does not concern itself with ordering beyond letting WP fall
 * through to its default behaviour for `s` searches.
 *
 * @author Tymofii Synianskyi
 */
final class SearchQuery {

	/**
	 * @return array<string, mixed>
	 */
	public function build( PostType $post_type, SearchRequest $request ): array {
		return [
			'post_type'              => $post_type->value,
			'post_status'            => 'publish',
			's'                      => $request->q,
			'paged'                  => $request->page,
			'posts_per_page'         => $request->per_page,
			'no_found_rows'          => false,
			'ignore_sticky_posts'    => true,
			'update_post_meta_cache' => true,
			'update_post_term_cache' => true,
		];
	}
}
