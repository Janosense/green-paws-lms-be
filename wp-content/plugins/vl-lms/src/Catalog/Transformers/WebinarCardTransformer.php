<?php

declare(strict_types=1);

namespace VL\LMS\Catalog\Transformers;

use VL\LMS\Catalog\PostType;
use VL\LMS\Catalog\TaxonomyTermTransformer;
use VL\LMS\Domain\CourseInstructor\CourseInstructor;
use WP_Post;
use WP_Term;

/**
 * Reshapes a single `vl_webinar` post into the catalog card payload.
 *
 * Webinar cards drop `type`/`duration_hours` and replace them with
 * `scheduled_start`, `scheduled_end`, `status`, `registration_open`.
 * The capacity portion of `registration_open` is intentionally a stub
 * for Phase 3.1 — see {@see self::registration_open()}.
 *
 * @author Tymofii Synianskyi
 */
final class WebinarCardTransformer {

	public function __construct(
		private readonly CoverImageTransformer $cover,
		private readonly LeadInstructorTransformer $lead_instructor,
		private readonly TaxonomyTermTransformer $term_transformer,
	) {
	}

	/**
	 * @param array<string, list<WP_Term>> $terms_by_taxonomy
	 * @param array<int, WP_Term>          $category_parents
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

		return [
			'id'                => $post_id,
			'slug'              => (string) $post->post_name,
			'title'             => (string) get_the_title( $post ),
			'excerpt'           => $this->plain_excerpt( $post ),
			'scheduled_start'   => $this->iso_meta( $post_id, '_vl_webinar_scheduled_start' ),
			'scheduled_end'     => $this->iso_meta( $post_id, '_vl_webinar_scheduled_end' ),
			'status'            => $this->status( $post_id ),
			'price'             => (float) get_post_meta( $post_id, '_vl_webinar_price', true ),
			'currency'          => $this->currency( $post_id ),
			'registration_open' => $this->registration_open( $post_id ),
			'difficulty'        => $this->difficulty( $terms_by_taxonomy['vl_difficulty'] ?? [] ),
			'categories'        => array_map(
				fn ( WP_Term $t ): array => $this->term_transformer->transform( $t, $category_parents ),
				$terms_by_taxonomy['vl_category'] ?? []
			),
			'specialties'       => array_map(
				fn ( WP_Term $t ): array => $this->term_transformer->transform( $t ),
				$terms_by_taxonomy['vl_specialty'] ?? []
			),
			'tags'              => array_map(
				fn ( WP_Term $t ): array => $this->term_transformer->transform( $t ),
				$terms_by_taxonomy['vl_tag'] ?? []
			),
			'cover'             => $this->cover->transform(
				(int) get_post_meta( $post_id, '_vl_webinar_cover_image_id', true )
			),
			'lead_instructor'   => $this->lead_instructor->transform( $lead ),
			'permalink'         => PostType::WEBINAR->permalink_prefix() . (string) $post->post_name,
		];
	}

	private function plain_excerpt( WP_Post $post ): string {
		$raw = (string) get_the_excerpt( $post );
		return trim( wp_strip_all_tags( $raw ) );
	}

	private function iso_meta( int $post_id, string $meta_key ): ?string {
		$value = (string) get_post_meta( $post_id, $meta_key, true );
		return '' === $value ? null : $value;
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

	/**
	 * Time-window registration check.
	 *
	 * Returns `true` when `now` is inside `[opens_at, closes_at]` (either
	 * bound may be empty/missing → that side is unbounded). The capacity
	 * gate (max_attendees) is deliberately not enforced yet — the
	 * `vl_webinar_registrations` table arrives in Phase 7.
	 *
	 * // TODO Phase 7: enforce capacity using the registrations table.
	 */
	private function registration_open( int $post_id ): bool {
		$opens_at  = (string) get_post_meta( $post_id, '_vl_webinar_registration_opens_at', true );
		$closes_at = (string) get_post_meta( $post_id, '_vl_webinar_registration_closes_at', true );
		$now       = time();

		if ( '' !== $opens_at ) {
			$opens_ts = strtotime( $opens_at );
			if ( false === $opens_ts || $now < $opens_ts ) {
				return false;
			}
		}

		if ( '' !== $closes_at ) {
			$closes_ts = strtotime( $closes_at );
			if ( false === $closes_ts || $now > $closes_ts ) {
				return false;
			}
		}

		return true;
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
