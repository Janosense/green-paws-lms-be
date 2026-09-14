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
	 * Singular label — the Ukrainian nominative noun (e.g. `Категорія`).
	 */
	abstract protected function singular_label(): string;

	/**
	 * Plural label — the Ukrainian nominative noun (e.g. `Категорії`).
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
	 * Labels are Ukrainian (the admin language), mirroring
	 * {@see \VL\LMS\CPT\AbstractCptRegistrar::build_labels()}: the
	 * singular/plural noun is interpolated after a colon (nominative case)
	 * so one template stays grammatically correct across genders, and each
	 * composite template stays wrapped in `__()` with the `vl-lms` text
	 * domain. Hierarchical-only keys (`parent_item`, `parent_item_colon`)
	 * are included unconditionally — WP ignores them for non-hierarchical
	 * taxonomies. Keys not set here keep WordPress's own localized defaults.
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
			'all_items'                  => $plural,
			'search_items'               => sprintf(
				/* translators: %s: plural taxonomy label */
				__( 'Пошук: %s', 'vl-lms' ),
				$plural
			),
			'popular_items'              => sprintf(
				/* translators: %s: plural taxonomy label */
				__( 'Популярні: %s', 'vl-lms' ),
				$plural
			),
			'parent_item'                => sprintf(
				/* translators: %s: singular taxonomy label */
				__( 'Батьківський елемент: %s', 'vl-lms' ),
				$singular
			),
			'parent_item_colon'          => sprintf(
				/* translators: %s: singular taxonomy label */
				__( 'Батьківський елемент (%s):', 'vl-lms' ),
				$singular
			),
			'edit_item'                  => sprintf(
				/* translators: %s: singular taxonomy label */
				__( 'Редагувати: %s', 'vl-lms' ),
				$singular
			),
			'update_item'                => sprintf(
				/* translators: %s: singular taxonomy label */
				__( 'Оновити: %s', 'vl-lms' ),
				$singular
			),
			'add_new_item'               => sprintf(
				/* translators: %s: singular taxonomy label */
				__( 'Додати: %s', 'vl-lms' ),
				$singular
			),
			'new_item_name'              => sprintf(
				/* translators: %s: singular taxonomy label */
				__( 'Назва нового елемента: %s', 'vl-lms' ),
				$singular
			),
			'separate_items_with_commas' => sprintf(
				/* translators: %s: plural taxonomy label */
				__( 'Розділяйте комами: %s', 'vl-lms' ),
				$plural
			),
			'add_or_remove_items'        => sprintf(
				/* translators: %s: plural taxonomy label */
				__( 'Додати або вилучити: %s', 'vl-lms' ),
				$plural
			),
			'choose_from_most_used'      => sprintf(
				/* translators: %s: plural taxonomy label */
				__( 'Вибрати з найуживаніших: %s', 'vl-lms' ),
				$plural
			),
			'not_found'                  => sprintf(
				/* translators: %s: plural taxonomy label */
				__( '%s не знайдено.', 'vl-lms' ),
				$plural
			),
			'items_list'                 => sprintf(
				/* translators: %s: plural taxonomy label */
				__( 'Список: %s', 'vl-lms' ),
				$plural
			),
			'items_list_navigation'      => sprintf(
				/* translators: %s: plural taxonomy label */
				__( 'Навігація списком: %s', 'vl-lms' ),
				$plural
			),
			'back_to_items'              => sprintf(
				/* translators: %s: plural taxonomy label */
				__( '&larr; Назад до списку: %s', 'vl-lms' ),
				$plural
			),
		];
	}
}
