<?php

declare(strict_types=1);

namespace VL\LMS\Catalog;

/**
 * Translates a {@see FilterRequest} into `WP_Query` argument arrays.
 *
 * The resulting array is intentionally close to a literal `WP_Query` $args
 * dump so the controller can inspect (and tests can assert) the contract
 * without booting the WP query layer. Within a single taxonomy the
 * relation is `IN` (OR-of-slugs); across taxonomies it is `AND`. The
 * `upcoming` sort layers a meta_query window on top — past or non-
 * scheduled webinars never appear in that listing.
 *
 * @author Tymofii Synianskyi
 */
final class CatalogQuery {

	/**
	 * @return array<string, mixed>
	 */
	public function build( FilterRequest $request ): array {
		$args = [
			'post_type'              => $request->post_type->value,
			'post_status'            => 'publish',
			'paged'                  => $request->page,
			'posts_per_page'         => $request->per_page,
			'no_found_rows'          => false,
			'ignore_sticky_posts'    => true,
			'update_post_meta_cache' => true,
			'update_post_term_cache' => true,
		];

		if ( '' !== $request->q ) {
			$args['s'] = $request->q;
		}

		$tax_query = $this->build_tax_query( $request );
		if ( [] !== $tax_query ) {
			$args['tax_query'] = $tax_query;
		}

		$this->apply_sort( $args, $request );

		return $args;
	}

	/**
	 * @return array<int|string, mixed>
	 */
	private function build_tax_query( FilterRequest $request ): array {
		$clauses = [];

		$pairs = [
			[ 'vl_category', $request->categories ],
			[ 'vl_specialty', $request->specialties ],
			[ 'vl_difficulty', $request->difficulties ],
			[ 'vl_tag', $request->tags ],
		];
		foreach ( $pairs as [$taxonomy, $slugs] ) {
			if ( [] === $slugs ) {
				continue;
			}
			$clauses[] = [
				'taxonomy' => $taxonomy,
				'field'    => 'slug',
				'terms'    => $slugs,
				'operator' => 'IN',
			];
		}

		if ( [] === $clauses ) {
			return [];
		}

		if ( count( $clauses ) === 1 ) {
			return $clauses;
		}

		return array_merge(
			[ 'relation' => 'AND' ],
			$clauses
		);
	}

	/**
	 * @param array<string, mixed> $args
	 */
	private function apply_sort( array &$args, FilterRequest $request ): void {
		switch ( $request->sort ) {
			case SortOrder::NEWEST:
				$args['orderby'] = 'date';
				$args['order']   = 'DESC';
				break;

			case SortOrder::OLDEST:
				$args['orderby'] = 'date';
				$args['order']   = 'ASC';
				break;

			case SortOrder::TITLE_ASC:
				$args['orderby'] = 'title';
				$args['order']   = 'ASC';
				break;

			case SortOrder::TITLE_DESC:
				$args['orderby'] = 'title';
				$args['order']   = 'DESC';
				break;

			case SortOrder::UPCOMING:
				$now = gmdate( 'Y-m-d\TH:i:s\Z' );

				$args['meta_query'] = [
					'relation'   => 'AND',
					'status'     => [
						'key'     => '_vl_webinar_status',
						'value'   => 'scheduled',
						'compare' => '=',
					],
					'start_date' => [
						'key'     => '_vl_webinar_scheduled_start',
						'value'   => $now,
						'compare' => '>=',
						'type'    => 'CHAR',
					],
				];
				$args['orderby']    = [ 'start_date' => 'ASC' ];
				break;
		}
	}
}
