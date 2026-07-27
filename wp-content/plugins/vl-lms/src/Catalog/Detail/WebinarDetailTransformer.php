<?php

declare(strict_types=1);

namespace VL\LMS\Catalog\Detail;

use VL\LMS\Catalog\PostType;
use VL\LMS\Catalog\TaxonomyTermTransformer;
use VL\LMS\Catalog\Transformers\CoverImageTransformer;
use VL\LMS\Domain\CourseInstructor\InstructorEntityType;
use VL\LMS\Repositories\CourseInstructorRepository;
use VL\LMS\Support\PlainText;
use WP_Post;
use WP_Term;

/**
 * Composes the full webinar detail response from a `vl_webinar` post.
 *
 * This class is the **trust boundary** for webinar private data. Zoom
 * credentials (`_vl_webinar_zoom_*`), the recording URL, and any future
 * private meta default to "not exposed" and require an explicit
 * decision (with a roadmap note) before being added to the public
 * shape. The `recording_offered` / `recording_access_days` pair is the
 * marketing-side substitute for the URL itself — enrolled users get
 * the URL through a Phase 7 controller, not through this one.
 *
 * Not declared `final` so unit tests can substitute a mock for the
 * orchestrator when asserting that the controller delegates correctly;
 * production code never extends it.
 *
 * @author Tymofii Synianskyi
 */
class WebinarDetailTransformer {

	public function __construct(
		private readonly CoverImageTransformer $cover,
		private readonly TaxonomyTermTransformer $term_transformer,
		private readonly InstructorListTransformer $instructor_list,
		private readonly MaterialsTransformer $materials,
		private readonly RegistrationWindow $registration_window,
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
			(int) get_post_meta( $post_id, '_vl_webinar_cover_image_id', true )
		);

		$assignments      = $this->instructors->list_for_entity( InstructorEntityType::WEBINAR, $post_id );
		$instructor_block = $this->instructor_list->transform( $assignments );

		$excerpt = $this->plain_excerpt( $post );

		$recording_access_days = (int) get_post_meta( $post_id, '_vl_webinar_recording_access_days', true );

		return [
			'id'                     => $post_id,
			'slug'                   => $slug,
			'title'                  => PlainText::from_html( (string) get_the_title( $post ) ),
			'excerpt'                => $excerpt,
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Invoking WP core's `the_content` filter so wpautop / shortcodes run on the post body.
			'content'                => (string) apply_filters( 'the_content', (string) $post->post_content ),
			'scheduled_start'        => $this->iso_meta( $post_id, '_vl_webinar_scheduled_start' ),
			'scheduled_end'          => $this->iso_meta( $post_id, '_vl_webinar_scheduled_end' ),
			'status'                 => $this->status( $post_id ),
			'price'                  => (float) get_post_meta( $post_id, '_vl_webinar_price', true ),
			'currency'               => $this->currency( $post_id ),
			'max_attendees'          => (int) get_post_meta( $post_id, '_vl_webinar_max_attendees', true ),
			'registration_opens_at'  => $this->iso_meta( $post_id, '_vl_webinar_registration_opens_at' ),
			'registration_closes_at' => $this->iso_meta( $post_id, '_vl_webinar_registration_closes_at' ),
			'registration_open'      => $this->registration_window->is_open( $post_id ),
			'preview_video_url'      => (string) get_post_meta( $post_id, '_vl_webinar_preview_video_url', true ),
			'recording_offered'      => $recording_access_days > 0,
			'recording_access_days'  => $recording_access_days,
			'materials'              => $this->materials->transform(
				get_post_meta( $post_id, '_vl_webinar_materials', true )
			),
			'difficulty'             => $this->difficulty( $terms_by_taxonomy['vl_difficulty'] ?? [] ),
			'categories'             => array_map(
				fn ( WP_Term $t ): array => $this->term_transformer->transform( $t, $category_parents ),
				$terms_by_taxonomy['vl_category'] ?? []
			),
			'specialties'            => array_map(
				fn ( WP_Term $t ): array => $this->term_transformer->transform( $t ),
				$terms_by_taxonomy['vl_specialty'] ?? []
			),
			'tags'                   => array_map(
				fn ( WP_Term $t ): array => $this->term_transformer->transform( $t ),
				$terms_by_taxonomy['vl_tag'] ?? []
			),
			'cover'                  => $cover,
			'instructors'            => $instructor_block,
			'seo'                    => $this->seo->transform( $post, PostType::WEBINAR, $excerpt, $cover ),
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
		return PlainText::from_html( (string) get_the_excerpt( $post ) );
	}

	private function status( int $post_id ): string {
		$status = (string) get_post_meta( $post_id, '_vl_webinar_status', true );
		return in_array( $status, [ 'scheduled', 'live', 'completed', 'cancelled' ], true )
			? $status
			: 'scheduled';
	}

	private function currency( int $post_id ): string {
		$currency = (string) get_post_meta( $post_id, '_vl_webinar_currency', true );
		return '' === $currency ? 'UAH' : $currency;
	}

	private function iso_meta( int $post_id, string $meta_key ): ?string {
		$value = (string) get_post_meta( $post_id, $meta_key, true );
		return '' === $value ? null : $value;
	}
}
