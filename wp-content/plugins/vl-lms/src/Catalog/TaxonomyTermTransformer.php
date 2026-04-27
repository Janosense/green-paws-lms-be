<?php

declare(strict_types=1);

namespace VL\LMS\Catalog;

use WP_Term;

/**
 * Reshapes a {@see WP_Term} into the catalog term DTO.
 *
 * Hierarchical taxonomies emit a `parent_slug` field (`null` for top-
 * level). Flat taxonomies omit the key entirely so consumers don't see
 * `null` literals where the field is meaningless.
 *
 * @author Tymofii Synianskyi
 */
final class TaxonomyTermTransformer {

	private const HIERARCHICAL = [ 'vl_category' ];

	/**
	 * @param array<int, WP_Term> $parent_lookup Map of `term_id => WP_Term` used to resolve parents
	 *                                           without firing extra queries.
	 *
	 * @return array{
	 *     id: int,
	 *     slug: string,
	 *     name: string,
	 *     count: int,
	 *     parent_slug?: string|null
	 * }
	 */
	public function transform( WP_Term $term, array $parent_lookup = [] ): array {
		$out = [
			'id'    => (int) $term->term_id,
			'slug'  => (string) $term->slug,
			'name'  => (string) $term->name,
			'count' => (int) $term->count,
		];

		if ( in_array( $term->taxonomy, self::HIERARCHICAL, true ) ) {
			$parent_id          = (int) $term->parent;
			$out['parent_slug'] = 0 === $parent_id
				? null
				: ( isset( $parent_lookup[ $parent_id ] )
					? (string) $parent_lookup[ $parent_id ]->slug
					: null );
		}

		return $out;
	}

	public function is_hierarchical( string $taxonomy ): bool {
		return in_array( $taxonomy, self::HIERARCHICAL, true );
	}
}
