<?php

declare(strict_types=1);

namespace VL\LMS\Catalog;

use InvalidArgumentException;
use VL\LMS\Catalog\Transformers\CourseCardTransformer;
use VL\LMS\Catalog\Transformers\WebinarCardTransformer;
use VL\LMS\Domain\CourseInstructor\CourseInstructor;
use VL\LMS\Domain\CourseInstructor\InstructorEntityType;
use VL\LMS\Repositories\CourseInstructorRepository;
use WP_Error;
use WP_Post;
use WP_Query;
use WP_REST_Request;
use WP_REST_Response;
use WP_Term;

/**
 * REST controller for `GET /vl/v1/catalog/courses` and
 * `GET /vl/v1/catalog/webinars`.
 *
 * Both endpoints delegate to the same code path; the only differences
 * (post type, sort whitelist, card shape) are isolated in
 * {@see PostType}, {@see SortOrder}, and the per-type transformer. The
 * controller pre-fetches lead instructors and term lists for the page's
 * post IDs in two extra queries (one batch lookup per axis), so a page
 * of N posts costs O(1) round-trips for the auxiliary data — see
 * {@see CourseInstructorRepository::find_leads_for_entities()} and
 * `wp_get_object_terms` with the array form.
 *
 * Caching: the natural insertion point for an object-cache layer is
 * {@see self::run_query()} for `WP_Query` results and the term/lead
 * batch lookups inside {@see self::transform_items()}. The transient or
 * `wp_cache_*` wrapper is intentionally not implemented in 3.1; add it
 * here when measured pageload times demand it.
 *
 * @author Tymofii Synianskyi
 */
final class CatalogController {

	public const string COURSES_ROUTE  = '/catalog/courses';
	public const string WEBINARS_ROUTE = '/catalog/webinars';

	public function __construct(
		private readonly string $rest_namespace,
		private readonly CatalogQuery $query_builder,
		private readonly CourseCardTransformer $course_card,
		private readonly WebinarCardTransformer $webinar_card,
		private readonly CourseInstructorRepository $instructors,
	) {
	}

	public function register_routes(): void {
		register_rest_route(
			$this->rest_namespace,
			self::COURSES_ROUTE,
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'list_courses' ],
				'permission_callback' => '__return_true',
				'args'                => $this->common_args(),
			]
		);

		register_rest_route(
			$this->rest_namespace,
			self::WEBINARS_ROUTE,
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'list_webinars' ],
				'permission_callback' => '__return_true',
				'args'                => $this->common_args(),
			]
		);
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_courses( WP_REST_Request $request ) {
		return $this->handle( PostType::COURSE, $request );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_webinars( WP_REST_Request $request ) {
		return $this->handle( PostType::WEBINAR, $request );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	private function handle( PostType $post_type, WP_REST_Request $request ) {
		try {
			$filter_request = FilterRequest::from_array( $post_type, $request->get_params() );
		} catch ( InvalidArgumentException $e ) {
			return new WP_Error(
				'vl_lms_invalid_sort',
				$e->getMessage(),
				[ 'status' => 400 ]
			);
		}

		$query    = $this->run_query( $filter_request );
		$posts    = $query->posts;
		$post_ids = array_map( static fn ( WP_Post $p ): int => (int) $p->ID, $posts );

		$items = $this->transform_items( $post_type, $posts, $post_ids );

		return rest_ensure_response(
			[
				'success' => true,
				'data'    => [
					'items'      => $items,
					'pagination' => [
						'page'        => $filter_request->page,
						'per_page'    => $filter_request->per_page,
						'total'       => (int) $query->found_posts,
						'total_pages' => (int) $query->max_num_pages,
					],
				],
			]
		);
	}

	private function run_query( FilterRequest $request ): WP_Query {
		return new WP_Query( $this->query_builder->build( $request ) );
	}

	/**
	 * @param list<WP_Post> $posts
	 * @param list<int>     $post_ids
	 *
	 * @return list<array<string, mixed>>
	 */
	private function transform_items( PostType $post_type, array $posts, array $post_ids ): array {
		if ( [] === $posts ) {
			return [];
		}

		$entity_type = PostType::COURSE === $post_type
			? InstructorEntityType::COURSE
			: InstructorEntityType::WEBINAR;

		$leads_by_post_id = $this->instructors->find_leads_for_entities( $entity_type, $post_ids );
		$terms_by_post_id = $this->prefetch_terms( $post_ids );
		$category_parents = $this->prefetch_category_parents( $terms_by_post_id );

		$out = [];
		foreach ( $posts as $post ) {
			$pid   = (int) $post->ID;
			$lead  = $leads_by_post_id[ $pid ] ?? null;
			$terms = $terms_by_post_id[ $pid ] ?? [];

			$out[] = PostType::COURSE === $post_type
				? $this->course_card->transform( $post, $lead instanceof CourseInstructor ? $lead : null, $terms, $category_parents )
				: $this->webinar_card->transform( $post, $lead instanceof CourseInstructor ? $lead : null, $terms, $category_parents );
		}

		return $out;
	}

	/**
	 * Batch-fetch all four taxonomies for every post on the page in one
	 * `wp_get_object_terms` call, then group by post id and taxonomy.
	 *
	 * @param list<int> $post_ids
	 *
	 * @return array<int, array<string, list<WP_Term>>>
	 */
	private function prefetch_terms( array $post_ids ): array {
		if ( [] === $post_ids ) {
			return [];
		}

		$terms = wp_get_object_terms(
			$post_ids,
			[ 'vl_category', 'vl_specialty', 'vl_difficulty', 'vl_tag' ],
			[ 'fields' => 'all_with_object_id' ]
		);

		if ( ! is_array( $terms ) ) {
			return [];
		}

		$grouped = [];
		foreach ( $terms as $term ) {
			if ( ! $term instanceof WP_Term ) {
				continue;
			}
			$pid = (int) ( $term->object_id ?? 0 );
			$grouped[ $pid ][ (string) $term->taxonomy ][] = $term;
		}

		return $grouped;
	}

	/**
	 * Resolve any non-zero `vl_category` parent IDs that appear in the
	 * page's terms, so the transformer can emit `parent_slug` without
	 * an extra round-trip per child term.
	 *
	 * @param array<int, array<string, list<WP_Term>>> $terms_by_post_id
	 *
	 * @return array<int, WP_Term>
	 */
	private function prefetch_category_parents( array $terms_by_post_id ): array {
		$parent_ids = [];
		foreach ( $terms_by_post_id as $by_taxonomy ) {
			foreach ( $by_taxonomy['vl_category'] ?? [] as $term ) {
				if ( $term instanceof WP_Term && (int) $term->parent > 0 ) {
					$parent_ids[ (int) $term->parent ] = true;
				}
			}
		}
		if ( [] === $parent_ids ) {
			return [];
		}

		$parents = get_terms(
			[
				'taxonomy'   => 'vl_category',
				'include'    => array_keys( $parent_ids ),
				'hide_empty' => false,
			]
		);
		if ( ! is_array( $parents ) ) {
			return [];
		}

		$out = [];
		foreach ( $parents as $parent ) {
			if ( $parent instanceof WP_Term ) {
				$out[ (int) $parent->term_id ] = $parent;
			}
		}
		return $out;
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function common_args(): array {
		return [
			'q'          => [
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			],
			'category'   => [
				'type'     => 'array',
				'required' => false,
				'items'    => [ 'type' => 'string' ],
			],
			'specialty'  => [
				'type'     => 'array',
				'required' => false,
				'items'    => [ 'type' => 'string' ],
			],
			'difficulty' => [
				'type'     => 'array',
				'required' => false,
				'items'    => [ 'type' => 'string' ],
			],
			'tag'        => [
				'type'     => 'array',
				'required' => false,
				'items'    => [ 'type' => 'string' ],
			],
			'page'       => [
				'type'              => 'integer',
				'required'          => false,
				'default'           => 1,
				'sanitize_callback' => 'absint',
			],
			'per_page'   => [
				'type'              => 'integer',
				'required'          => false,
				'default'           => FilterRequest::PER_PAGE_DEFAULT,
				'sanitize_callback' => 'absint',
			],
			'sort'       => [
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			],
		];
	}
}
