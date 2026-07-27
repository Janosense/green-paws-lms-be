<?php

declare(strict_types=1);

namespace VL\LMS\Catalog\Transformers;

use VL\LMS\Catalog\PostType;
use VL\LMS\Catalog\TaxonomyTermTransformer;
use VL\LMS\Domain\CourseInstructor\CourseInstructor;
use VL\LMS\Support\PlainText;
use WP_Post;
use WP_Term;

/**
 * Reshapes a single `vl_course` post into the catalog card payload.
 *
 * The transformer never queries; it consumes pre-fetched maps from the
 * controller (`leads_by_post_id`, `terms_by_post_id`) so that a page of
 * N posts produces O(1) round-trips for the auxiliary data.
 *
 * @author Tymofii Synianskyi
 */
final class CourseCardTransformer {

	public function __construct(
		private readonly CoverImageTransformer $cover,
		private readonly LeadInstructorTransformer $lead_instructor,
		private readonly TaxonomyTermTransformer $term_transformer,
	) {
	}

	/**
	 * @param array<string, list<WP_Term>> $terms_by_taxonomy Map keyed by taxonomy slug.
	 * @param array<int, WP_Term>          $category_parents  Term-id → WP_Term map for `vl_category` parents.
	 *
	 * @return array<string, mixed>
	 */
	public function transform(
		WP_Post $post,
		?CourseInstructor $lead,
		array $terms_by_taxonomy,
		array $category_parents = []
	): array {
		$post_id = (int) $post->ID;

		$card = [
			'id'              => $post_id,
			'slug'            => (string) $post->post_name,
			'title'           => PlainText::from_html( (string) get_the_title( $post ) ),
			'excerpt'         => $this->plain_excerpt( $post ),
			'type'            => $this->type( $post_id ),
			'duration_hours'  => (float) get_post_meta( $post_id, '_vl_course_duration_hours', true ),
			'price'           => (float) get_post_meta( $post_id, '_vl_course_price', true ),
			'currency'        => $this->currency( $post_id ),
			'enrollment_open' => (bool) get_post_meta( $post_id, '_vl_course_enrollment_open', true ),
			'difficulty'      => $this->difficulty( $terms_by_taxonomy['vl_difficulty'] ?? [] ),
			'categories'      => array_map(
				fn ( WP_Term $t ): array => $this->term_transformer->transform( $t, $category_parents ),
				$terms_by_taxonomy['vl_category'] ?? []
			),
			'specialties'     => array_map(
				fn ( WP_Term $t ): array => $this->term_transformer->transform( $t ),
				$terms_by_taxonomy['vl_specialty'] ?? []
			),
			'tags'            => array_map(
				fn ( WP_Term $t ): array => $this->term_transformer->transform( $t ),
				$terms_by_taxonomy['vl_tag'] ?? []
			),
			'cover'           => $this->cover->transform(
				(int) get_post_meta( $post_id, '_vl_course_cover_image_id', true )
			),
			'lead_instructor' => $this->lead_instructor->transform( $lead ),
			'permalink'       => PostType::COURSE->permalink_prefix() . (string) $post->post_name,
		];

		return $card;
	}

	private function plain_excerpt( WP_Post $post ): string {
		return PlainText::from_html( (string) get_the_excerpt( $post ) );
	}

	private function type( int $post_id ): string {
		$type = (string) get_post_meta( $post_id, '_vl_course_type', true );
		return in_array( $type, [ 'self_paced', 'cohort' ], true ) ? $type : 'self_paced';
	}

	private function currency( int $post_id ): string {
		$currency = (string) get_post_meta( $post_id, '_vl_course_currency', true );
		return '' === $currency ? 'UAH' : $currency;
	}

	/**
	 * @param list<WP_Term> $terms
	 *
	 * @return array{slug: string, name: string}|null
	 */
	private function difficulty( array $terms ): ?array {
		$first = $terms[0] ?? null;
		if ( ! $first instanceof WP_Term ) {
			return null;
		}
		return [
			'slug' => (string) $first->slug,
			'name' => (string) $first->name,
		];
	}
}
