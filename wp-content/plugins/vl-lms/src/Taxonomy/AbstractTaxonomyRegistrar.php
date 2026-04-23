<?php

declare(strict_types=1);

namespace VL\LMS\Taxonomy;

/**
 * Base class for every VL LMS taxonomy registrar.
 *
 * Encapsulates the shared `register_taxonomy` argument set (headless
 * defaults — private, no REST, admin-only UI) so concrete subclasses only
 * describe what is specific to their taxonomy: slug, object types, labels,
 * hierarchy, and an optional capability override.
 *
 * Terms are not exposed through `wp/v2`; the Nuxt frontend consumes
 * taxonomy data exclusively through the `vl/v1/*` controllers. The classic
 * wp-admin UI is left on (`show_ui`, `show_admin_column`) because that is
 * where editors will manage terms in Phase 1.
 *
 * @author Tymofii Synianskyi
 */
abstract class AbstractTaxonomyRegistrar {

	/**
	 * Taxonomy slug — e.g. `vl_category`.
	 */
	abstract protected function taxonomy(): string;

	/**
	 * Post types this taxonomy attaches to.
	 *
	 * @return list<string>
	 */
	abstract protected function object_types(): array;

	/**
	 * Human-readable singular label, untranslated (e.g. `Category`).
	 */
	abstract protected function singular_label(): string;

	/**
	 * Human-readable plural label, untranslated (e.g. `Categories`).
	 */
	abstract protected function plural_label(): string;

	/**
	 * Whether the taxonomy behaves hierarchically (categories) or as a
	 * flat vocabulary (tags).
	 */
	abstract protected function hierarchical(): bool;

	/**
	 * Optional capability override for term management.
	 *
	 * Return `null` to fall back to WP defaults (`manage_categories` /
	 * `edit_posts`). Return an array with the four standard keys
	 * (`manage_terms`, `edit_terms`, `delete_terms`, `assign_terms`) to
	 * override.
	 *
	 * @return array{manage_terms: string, edit_terms: string, delete_terms: string, assign_terms: string}|null
	 */
	abstract protected function capabilities(): ?array;

	/**
	 * Register the taxonomy against its configured object types.
	 *
	 * Intended to run on the `init` action, after the object post types
	 * have been registered.
	 */
	public function register(): void {
		register_taxonomy(
			$this->taxonomy(),
			$this->object_types(),
			$this->build_taxonomy_args()
		);
	}

	/**
	 * Assemble the full argument array for `register_taxonomy`.
	 *
	 * Omits the `capabilities` key entirely when `capabilities()` returns
	 * `null` so WP applies its default capability map.
	 *
	 * @return array<string, mixed>
	 */
	protected function build_taxonomy_args(): array {
		$args = [
			'labels'             => $this->build_labels(),
			'hierarchical'       => $this->hierarchical(),
			'public'             => false,
			'publicly_queryable' => false,
			'show_ui'            => true,
			'show_in_nav_menus'  => false,
			'show_in_rest'       => false,
			'query_var'          => false,
			'rewrite'            => false,
			'show_admin_column'  => true,
		];

		$capabilities = $this->capabilities();
		if ( null !== $capabilities ) {
			$args['capabilities'] = $capabilities;
		}

		return $args;
	}

	/**
	 * Build the standard WP taxonomy labels array for wp-admin.
	 *
	 * Each user-visible label is wrapped in `__()` with the `vl-lms` text
	 * domain. Hierarchical-only keys (`parent_item`, `parent_item_colon`)
	 * are included unconditionally — WP ignores them for non-hierarchical
	 * taxonomies.
	 *
	 * @return array<string, string>
	 */
	protected function build_labels(): array {
		$singular = $this->singular_label();
		$plural   = $this->plural_label();

		return [
			'name'                       => $plural,
			'singular_name'              => $singular,
			'menu_name'                  => $plural,
			'all_items'                  => sprintf(
				/* translators: %s: plural taxonomy label */
				__( 'All %s', 'vl-lms' ),
				$plural
			),
			'search_items'               => sprintf(
				/* translators: %s: plural taxonomy label */
				__( 'Search %s', 'vl-lms' ),
				$plural
			),
			'popular_items'              => sprintf(
				/* translators: %s: plural taxonomy label */
				__( 'Popular %s', 'vl-lms' ),
				$plural
			),
			'parent_item'                => sprintf(
				/* translators: %s: singular taxonomy label */
				__( 'Parent %s', 'vl-lms' ),
				$singular
			),
			'parent_item_colon'          => sprintf(
				/* translators: %s: singular taxonomy label */
				__( 'Parent %s:', 'vl-lms' ),
				$singular
			),
			'edit_item'                  => sprintf(
				/* translators: %s: singular taxonomy label */
				__( 'Edit %s', 'vl-lms' ),
				$singular
			),
			'update_item'                => sprintf(
				/* translators: %s: singular taxonomy label */
				__( 'Update %s', 'vl-lms' ),
				$singular
			),
			'add_new_item'               => sprintf(
				/* translators: %s: singular taxonomy label */
				__( 'Add New %s', 'vl-lms' ),
				$singular
			),
			'new_item_name'              => sprintf(
				/* translators: %s: singular taxonomy label */
				__( 'New %s Name', 'vl-lms' ),
				$singular
			),
			'separate_items_with_commas' => sprintf(
				/* translators: %s: plural taxonomy label (lowercase) */
				__( 'Separate %s with commas', 'vl-lms' ),
				strtolower( $plural )
			),
			'add_or_remove_items'        => sprintf(
				/* translators: %s: plural taxonomy label (lowercase) */
				__( 'Add or remove %s', 'vl-lms' ),
				strtolower( $plural )
			),
			'choose_from_most_used'      => sprintf(
				/* translators: %s: plural taxonomy label (lowercase) */
				__( 'Choose from the most used %s', 'vl-lms' ),
				strtolower( $plural )
			),
			'not_found'                  => sprintf(
				/* translators: %s: plural taxonomy label (lowercase) */
				__( 'No %s found.', 'vl-lms' ),
				strtolower( $plural )
			),
			'items_list'                 => sprintf(
				/* translators: %s: plural taxonomy label */
				__( '%s list', 'vl-lms' ),
				$plural
			),
			'items_list_navigation'      => sprintf(
				/* translators: %s: plural taxonomy label */
				__( '%s list navigation', 'vl-lms' ),
				$plural
			),
			'back_to_items'              => sprintf(
				/* translators: %s: plural taxonomy label */
				__( '&larr; Back to %s', 'vl-lms' ),
				$plural
			),
		];
	}
}
