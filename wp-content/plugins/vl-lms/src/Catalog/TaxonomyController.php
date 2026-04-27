<?php

declare(strict_types=1);

namespace VL\LMS\Catalog;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_Term;

/**
 * REST controller for `GET /vl/v1/taxonomies/{taxonomy}`.
 *
 * Powers filter UIs in the catalog and search pages. Always public; the
 * data is the same WP that wp-admin shows to editors. Hierarchical
 * taxonomies emit a `parent_slug` field; flat taxonomies omit the key.
 *
 * @author Tymofii Synianskyi
 */
final class TaxonomyController {

	public const string ROUTE = '/taxonomies/(?P<taxonomy>[\w_-]+)';

	private const ALLOWED_TAXONOMIES = [
		'vl_category',
		'vl_specialty',
		'vl_difficulty',
		'vl_tag',
	];

	private const ALLOWED_POST_TYPES = [
		'vl_course',
		'vl_webinar',
	];

	public function __construct(
		private readonly string $rest_namespace,
		private readonly TaxonomyTermTransformer $transformer,
	) {
	}

	public function register_routes(): void {
		register_rest_route(
			$this->rest_namespace,
			self::ROUTE,
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'list_terms' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'taxonomy'  => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					],
					'post_type' => [
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_key',
					],
					'in_use'    => [
						'type'              => 'boolean',
						'required'          => false,
						'default'           => false,
						'sanitize_callback' => 'rest_sanitize_boolean',
					],
				],
			]
		);
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_terms( WP_REST_Request $request ) {
		$taxonomy = (string) $request->get_param( 'taxonomy' );
		if ( ! in_array( $taxonomy, self::ALLOWED_TAXONOMIES, true ) ) {
			return new WP_Error(
				'vl_lms_invalid_taxonomy',
				sprintf( 'Unknown taxonomy "%s".', $taxonomy ),
				[ 'status' => 400 ]
			);
		}

		$post_type_param = $request->get_param( 'post_type' );
		$post_type       = is_string( $post_type_param ) && '' !== $post_type_param ? $post_type_param : null;
		if ( null !== $post_type && ! in_array( $post_type, self::ALLOWED_POST_TYPES, true ) ) {
			return new WP_Error(
				'vl_lms_invalid_post_type',
				sprintf( 'Unknown post_type "%s".', $post_type ),
				[ 'status' => 400 ]
			);
		}

		$in_use = (bool) $request->get_param( 'in_use' );

		$terms = $this->fetch_terms( $taxonomy, $post_type );
		if ( $in_use ) {
			$terms = array_values(
				array_filter( $terms, static fn ( WP_Term $t ): bool => (int) $t->count > 0 )
			);
		}

		$terms = $this->order_terms( $terms );

		$parent_lookup = [];
		if ( $this->transformer->is_hierarchical( $taxonomy ) ) {
			foreach ( $terms as $t ) {
				$parent_lookup[ (int) $t->term_id ] = $t;
			}
		}

		$items = array_map(
			fn ( WP_Term $t ): array => $this->transformer->transform( $t, $parent_lookup ),
			$terms
		);

		return rest_ensure_response(
			[
				'success' => true,
				'data'    => [
					'items' => $items,
				],
			]
		);
	}

	/**
	 * @return list<WP_Term>
	 */
	private function fetch_terms( string $taxonomy, ?string $post_type ): array {
		$args = [
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
		];

		if ( null !== $post_type ) {
			$args['object_ids'] = $this->published_post_ids( $post_type );
			if ( [] === $args['object_ids'] ) {
				return [];
			}
		}

		$terms = get_terms( $args );
		if ( ! is_array( $terms ) ) {
			return [];
		}

		$out = [];
		foreach ( $terms as $term ) {
			if ( $term instanceof WP_Term ) {
				$out[] = $term;
			}
		}
		return $out;
	}

	/**
	 * Sort hierarchical taxonomies parents-before-children (depth-first
	 * by name), flat taxonomies alphabetically. Both use a stable
	 * comparator on `name` ASC.
	 *
	 * @param list<WP_Term> $terms
	 *
	 * @return list<WP_Term>
	 */
	private function order_terms( array $terms ): array {
		usort(
			$terms,
			static fn ( WP_Term $a, WP_Term $b ): int =>
				strcmp( (string) $a->name, (string) $b->name )
		);

		// Hierarchical sort: parents first, then children of each parent.
		$has_hierarchy = false;
		foreach ( $terms as $t ) {
			if ( (int) $t->parent > 0 ) {
				$has_hierarchy = true;
				break;
			}
		}
		if ( ! $has_hierarchy ) {
			return $terms;
		}

		$by_id = [];
		foreach ( $terms as $t ) {
			$by_id[ (int) $t->term_id ] = $t;
		}
		$children = [];
		$roots    = [];
		foreach ( $terms as $t ) {
			$parent = (int) $t->parent;
			if ( 0 === $parent || ! isset( $by_id[ $parent ] ) ) {
				$roots[] = $t;
			} else {
				$children[ $parent ][] = $t;
			}
		}

		$out  = [];
		$walk = function ( WP_Term $term ) use ( &$walk, &$children, &$out ): void {
			$out[] = $term;
			foreach ( $children[ (int) $term->term_id ] ?? [] as $child ) {
				$walk( $child );
			}
		};
		foreach ( $roots as $root ) {
			$walk( $root );
		}

		return $out;
	}

	/**
	 * @return list<int>
	 */
	private function published_post_ids( string $post_type ): array {
		$ids = get_posts(
			[
				'post_type'        => $post_type,
				'post_status'      => 'publish',
				'numberposts'      => -1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => false,
			]
		);

		if ( ! is_array( $ids ) ) {
			return [];
		}

		$out = [];
		foreach ( $ids as $id ) {
			$out[] = (int) $id;
		}
		return $out;
	}
}
