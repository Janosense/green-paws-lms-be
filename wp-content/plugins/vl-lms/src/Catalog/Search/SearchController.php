<?php

declare(strict_types=1);

namespace VL\LMS\Catalog\Search;

use InvalidArgumentException;
use VL\LMS\Catalog\PostType;
use VL\LMS\Catalog\Transformers\CourseCardTransformer;
use VL\LMS\Catalog\Transformers\WebinarCardTransformer;
use VL\LMS\Domain\CourseInstructor\CourseInstructor;
use VL\LMS\Domain\CourseInstructor\InstructorEntityType;
use VL\LMS\Repositories\CourseInstructorRepository;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_Term;

/**
 * REST controller for `GET /vl/v1/search`.
 *
 * Cross-type search across `vl_course` and `vl_webinar` posts. The
 * response is shaped as two parallel sub-objects (`courses`, `webinars`),
 * each carrying the same card payload as the Phase 3.1 list endpoints
 * (byte-for-byte identical, since the same transformers run). Pagination
 * is shared: `?page=2` paginates both sub-arrays independently to page
 * 2 of their respective totals.
 *
 * Relevance ranking is delegated to {@see RelevanceRanker}, which
 * scopes its filters to a single `WP_Query` instance and tears them down
 * as soon as the query has run. Failure modes inside the ranker fall
 * through to default WP behaviour (title-or-content `s`, date order),
 * so the endpoint is shippable even if relevance ranking is disabled.
 *
 * @author Tymofii Synianskyi
 */
final class SearchController {

	public const string ROUTE = '/search';

	public function __construct(
		private readonly string $rest_namespace,
		private readonly SearchQuery $query_builder,
		private readonly SearchQueryRunner $runner,
		private readonly RelevanceRanker $ranker,
		private readonly CourseCardTransformer $course_card,
		private readonly WebinarCardTransformer $webinar_card,
		private readonly CourseInstructorRepository $instructors,
	) {
	}

	public function register_routes(): void {
		register_rest_route(
			$this->rest_namespace,
			self::ROUTE,
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'search' ],
				'permission_callback' => '__return_true',
				'args'                => $this->args(),
			]
		);
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function search( WP_REST_Request $request ) {
		try {
			$search_request = SearchRequest::from_array( $request->get_params() );
		} catch ( InvalidArgumentException ) {
			return new WP_Error(
				'vl_lms_search_q_required',
				__( 'Search query is required', 'vl-lms' ),
				[ 'status' => 400 ]
			);
		}

		$courses_section  = $this->run_section( PostType::COURSE, $search_request );
		$webinars_section = $this->run_section( PostType::WEBINAR, $search_request );

		return rest_ensure_response(
			[
				'success' => true,
				'data'    => [
					'q'        => $search_request->q,
					'courses'  => $courses_section,
					'webinars' => $webinars_section,
				],
			]
		);
	}

	/**
	 * @return array{
	 *     items: list<array<string, mixed>>,
	 *     pagination: array{page: int, per_page: int, total: int, total_pages: int}
	 * }
	 */
	private function run_section( PostType $post_type, SearchRequest $request ): array {
		$args   = $this->query_builder->build( $post_type, $request );
		$result = $this->runner->run( $args, $this->ranker, $request->q );

		$posts    = $result['posts'];
		$post_ids = array_map( static fn ( WP_Post $p ): int => (int) $p->ID, $posts );

		return [
			'items'      => $this->transform_items( $post_type, $posts, $post_ids ),
			'pagination' => [
				'page'        => $request->page,
				'per_page'    => $request->per_page,
				'total'       => $result['total'],
				'total_pages' => $result['total_pages'],
			],
		];
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
	private function args(): array {
		return [
			'q'        => [
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			],
			'page'     => [
				'type'              => 'integer',
				'required'          => false,
				'default'           => 1,
				'sanitize_callback' => 'absint',
			],
			'per_page' => [
				'type'              => 'integer',
				'required'          => false,
				'default'           => SearchRequest::PER_PAGE_DEFAULT,
				'sanitize_callback' => 'absint',
			],
		];
	}
}
