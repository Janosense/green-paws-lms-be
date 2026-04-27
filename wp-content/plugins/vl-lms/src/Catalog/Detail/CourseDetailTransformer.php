<?php

declare(strict_types=1);

namespace VL\LMS\Catalog\Detail;

use VL\LMS\Catalog\PostType;
use VL\LMS\Catalog\TaxonomyTermTransformer;
use VL\LMS\Catalog\Transformers\CoverImageTransformer;
use VL\LMS\Domain\CourseInstructor\InstructorEntityType;
use VL\LMS\Repositories\CourseInstructorRepository;
use WP_Post;
use WP_Term;

/**
 * Composes the full course detail response from a `vl_course` post.
 *
 * Query shape — total round trips per request:
 *
 *   - one `wp_get_object_terms` for the four taxonomies on this post,
 *   - one repository call for the full instructor list ({@see CourseInstructorRepository::list_for_entity()}),
 *   - up to three queries inside {@see CurriculumTransformer} (modules,
 *     lessons-per-module batched, orphan lessons),
 *   - zero further queries from the cover / instructor list / SEO
 *     transformers — those are pure functions of pre-fetched data.
 *
 * `apply_filters('the_content', …)` is invoked once on the post body so
 * shortcodes and embed handlers run; the frontend renders the result
 * inside a sanitized container, so retaining HTML tags is intentional.
 *
 * Not declared `final` so unit tests can substitute a mock for the
 * orchestrator when asserting that the controller delegates correctly;
 * production code never extends it.
 *
 * @author Tymofii Synianskyi
 */
class CourseDetailTransformer {

	public function __construct(
		private readonly CoverImageTransformer $cover,
		private readonly TaxonomyTermTransformer $term_transformer,
		private readonly InstructorListTransformer $instructor_list,
		private readonly CurriculumTransformer $curriculum,
		private readonly SeoBlockTransformer $seo,
		private readonly CourseInstructorRepository $instructors,
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function transform( WP_Post $post ): array {
		$post_id = (int) $post->ID;
		$slug    = (string) $post->post_name;

		$terms_by_taxonomy = $this->fetch_terms( $post_id );
		$category_parents  = $this->resolve_category_parents( $terms_by_taxonomy );

		$cover = $this->cover->transform(
			(int) get_post_meta( $post_id, '_vl_course_cover_image_id', true )
		);

		$assignments      = $this->instructors->list_for_entity( InstructorEntityType::COURSE, $post_id );
		$instructor_block = $this->instructor_list->transform( $assignments );

		$excerpt = $this->plain_excerpt( $post );

		$certificate_enabled = (bool) get_post_meta( $post_id, '_vl_course_certificate_enabled', true );
		$passing_threshold   = $certificate_enabled
			? (int) get_post_meta( $post_id, '_vl_course_passing_threshold', true )
			: null;

		return [
			'id'                   => $post_id,
			'slug'                 => $slug,
			'title'                => (string) get_the_title( $post ),
			'excerpt'              => $excerpt,
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Invoking WP core's `the_content` filter so wpautop / shortcodes run on the post body.
			'content'              => (string) apply_filters( 'the_content', (string) $post->post_content ),
			'type'                 => $this->course_type( $post_id ),
			'duration_hours'       => (float) get_post_meta( $post_id, '_vl_course_duration_hours', true ),
			'price'                => (float) get_post_meta( $post_id, '_vl_course_price', true ),
			'currency'             => $this->currency( $post_id ),
			'enrollment_open'      => (bool) get_post_meta( $post_id, '_vl_course_enrollment_open', true ),
			'enrollment_opens_at'  => $this->iso_meta( $post_id, '_vl_course_enrollment_opens_at' ),
			'enrollment_closes_at' => $this->iso_meta( $post_id, '_vl_course_enrollment_closes_at' ),
			'starts_at'            => $this->iso_meta( $post_id, '_vl_course_starts_at' ),
			'ends_at'              => $this->iso_meta( $post_id, '_vl_course_ends_at' ),
			'max_students'         => (int) get_post_meta( $post_id, '_vl_course_max_students', true ),
			'preview_video_url'    => (string) get_post_meta( $post_id, '_vl_course_preview_video_url', true ),
			'certificate_enabled'  => $certificate_enabled,
			'passing_threshold'    => $passing_threshold,
			'difficulty'           => $this->difficulty( $terms_by_taxonomy['vl_difficulty'] ?? [] ),
			'categories'           => array_map(
				fn ( WP_Term $t ): array => $this->term_transformer->transform( $t, $category_parents ),
				$terms_by_taxonomy['vl_category'] ?? []
			),
			'specialties'          => array_map(
				fn ( WP_Term $t ): array => $this->term_transformer->transform( $t ),
				$terms_by_taxonomy['vl_specialty'] ?? []
			),
			'tags'                 => array_map(
				fn ( WP_Term $t ): array => $this->term_transformer->transform( $t ),
				$terms_by_taxonomy['vl_tag'] ?? []
			),
			'cover'                => $cover,
			'instructors'          => $instructor_block,
			'curriculum'           => $this->curriculum->transform( $post_id ),
			'seo'                  => $this->seo->transform( $post, PostType::COURSE, $excerpt, $cover ),
		];
	}

	/**
	 * @return array<string, list<WP_Term>>
	 */
	private function fetch_terms( int $post_id ): array {
		$terms = wp_get_object_terms(
			$post_id,
			[ 'vl_category', 'vl_specialty', 'vl_difficulty', 'vl_tag' ]
		);
		if ( ! is_array( $terms ) ) {
			return [];
		}

		$grouped = [];
		foreach ( $terms as $term ) {
			if ( ! $term instanceof WP_Term ) {
				continue;
			}
			$grouped[ (string) $term->taxonomy ][] = $term;
		}
		return $grouped;
	}

	/**
	 * @param array<string, list<WP_Term>> $terms_by_taxonomy
	 *
	 * @return array<int, WP_Term>
	 */
	private function resolve_category_parents( array $terms_by_taxonomy ): array {
		$parent_ids = [];
		foreach ( $terms_by_taxonomy['vl_category'] ?? [] as $term ) {
			if ( (int) $term->parent > 0 ) {
				$parent_ids[ (int) $term->parent ] = true;
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

	private function plain_excerpt( WP_Post $post ): string {
		$raw = (string) get_the_excerpt( $post );
		return trim( wp_strip_all_tags( $raw ) );
	}

	private function course_type( int $post_id ): string {
		$type = (string) get_post_meta( $post_id, '_vl_course_type', true );
		return in_array( $type, [ 'self_paced', 'cohort' ], true ) ? $type : 'self_paced';
	}

	private function currency( int $post_id ): string {
		$currency = (string) get_post_meta( $post_id, '_vl_course_currency', true );
		return '' === $currency ? 'UAH' : $currency;
	}

	private function iso_meta( int $post_id, string $meta_key ): ?string {
		$value = (string) get_post_meta( $post_id, $meta_key, true );
		return '' === $value ? null : $value;
	}
}
