<?php

declare(strict_types=1);

namespace VL\LMS\Taxonomy;

/**
 * Seeds the closed vocabulary for `vl_difficulty` on plugin activation.
 *
 * The three canonical terms (`basic`, `advanced`, `expert`) live on
 * {@see DifficultyTaxonomy::DEFAULT_TERMS}; this class is the only place
 * that writes them to the taxonomy table. Re-activation is always
 * idempotent — existing slugs are never touched, so admin-side renames
 * (e.g. "Expert" → "Master") survive a deactivate/reactivate cycle.
 *
 * Taxonomies are registered on `init` on every request, but that hook
 * cannot persist terms because it would re-seed on every page load. The
 * one-shot work belongs in the activation sequence, which is why this
 * installer is called from {@see \VL\LMS\Activator::activate()} rather
 * than from the taxonomy registrar.
 *
 * @author Tymofii Synianskyi
 */
final class DifficultyTermsInstaller {

	public static function install(): void {
		if ( ! taxonomy_exists( 'vl_difficulty' ) ) {
			return;
		}

		foreach ( DifficultyTaxonomy::DEFAULT_TERMS as $slug => $display_name ) {
			if ( term_exists( $slug, 'vl_difficulty' ) ) {
				continue;
			}

			$result = wp_insert_term(
				self::translate_term_name( $slug, $display_name ),
				'vl_difficulty',
				[ 'slug' => $slug ]
			);

			if ( is_wp_error( $result ) ) {
				// Best-effort seeding — a term insert failure (e.g. race
				// condition with a parallel request) should not abort the
				// rest of the activation sequence.
				continue;
			}
		}
	}

	/**
	 * WPCS requires `__()` to take a literal string — keep the mapping
	 * here so the taxonomy class stays i18n-free.
	 */
	private static function translate_term_name( string $slug, string $fallback ): string {
		return match ( $slug ) {
			'basic'    => __( 'Basic', 'vl-lms' ),
			'advanced' => __( 'Advanced', 'vl-lms' ),
			'expert'   => __( 'Expert', 'vl-lms' ),
			default    => $fallback,
		};
	}
}
