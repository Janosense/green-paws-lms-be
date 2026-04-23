<?php

declare(strict_types=1);

namespace VL\LMS\CPT;

/**
 * Base class for every VL LMS custom post type registrar.
 *
 * Encapsulates the shared `register_post_type` argument set (headless
 * defaults — private, no REST, admin-only UI) so concrete subclasses only
 * describe what is specific to their post type: slug, labels, capability
 * shape, supports array, menu icon, and meta fields.
 *
 * Subclasses declare meta via `meta_fields()` and the base `register()`
 * method wires both the post type and its meta keys in a single pass. All
 * meta keys are registered with `show_in_rest => false` because the
 * frontend consumes data exclusively through the `vl/v1` controllers.
 *
 * @author Tymofii Synianskyi
 */
abstract class AbstractCptRegistrar {

	/**
	 * CPT slug — e.g. `vl_course`.
	 */
	abstract protected function post_type(): string;

	/**
	 * Human-readable singular label, untranslated (e.g. `Course`).
	 */
	abstract protected function singular_label(): string;

	/**
	 * Human-readable plural label, untranslated (e.g. `Courses`).
	 */
	abstract protected function plural_label(): string;

	/**
	 * Capability-type pair `[ singular, plural ]` used by WP to derive
	 * meta and primitive capabilities when `map_meta_cap` is enabled.
	 *
	 * @return array{0: string, 1: string}
	 */
	abstract protected function capability_type(): array;

	/**
	 * WP editor feature flags passed to `register_post_type`.
	 *
	 * @return list<string>
	 */
	abstract protected function supports(): array;

	/**
	 * Dashicons slug for the wp-admin menu entry, or null to inherit the
	 * default post icon.
	 */
	abstract protected function menu_icon(): ?string;

	/**
	 * Whether the post type behaves like a page (hierarchical parent/child).
	 */
	abstract protected function hierarchical(): bool;

	/**
	 * Whether the CPT surfaces its own top-level wp-admin menu.
	 */
	abstract protected function show_in_menu(): bool;

	/**
	 * Meta fields to register via `register_post_meta`.
	 *
	 * Keys are meta keys, values are argument arrays in the shape expected
	 * by `register_post_meta` — minimally `type`, `single`, `default`,
	 * `show_in_rest`, `sanitize_callback`, `auth_callback`, `description`.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	abstract protected function meta_fields(): array;

	/**
	 * Register the post type and all of its meta keys.
	 *
	 * Intended to run on the `init` action.
	 */
	public function register(): void {
		$post_type = $this->post_type();

		register_post_type( $post_type, $this->build_post_type_args() );

		foreach ( $this->meta_fields() as $meta_key => $meta_args ) {
			register_post_meta( $post_type, $meta_key, $meta_args );
		}
	}

	/**
	 * Assemble the full argument array for `register_post_type`.
	 *
	 * @return array<string, mixed>
	 */
	protected function build_post_type_args(): array {
		return [
			'labels'              => $this->build_labels(),
			'public'              => false,
			'publicly_queryable'  => false,
			'show_ui'             => true,
			'show_in_menu'        => $this->show_in_menu(),
			'show_in_nav_menus'   => false,
			'show_in_rest'        => false,
			'query_var'           => false,
			'rewrite'             => false,
			'has_archive'         => false,
			'exclude_from_search' => true,
			'hierarchical'        => $this->hierarchical(),
			'capability_type'     => $this->capability_type(),
			'map_meta_cap'        => true,
			'supports'            => $this->supports(),
			'menu_icon'           => $this->menu_icon(),
			'can_export'          => true,
			'delete_with_user'    => false,
		];
	}

	/**
	 * Build the standard WP labels array for wp-admin.
	 *
	 * Each user-visible label is wrapped in `__()` with the `vl-lms` text
	 * domain. Translators see per-slot strings rather than sprintf
	 * templates so they can tailor each label.
	 *
	 * @return array<string, string>
	 */
	protected function build_labels(): array {
		$singular = $this->singular_label();
		$plural   = $this->plural_label();

		return [
			'name'                  => $plural,
			'singular_name'         => $singular,
			'menu_name'             => $plural,
			'name_admin_bar'        => $singular,
			'all_items'             => $plural,
			'add_new_item'          => sprintf(
				/* translators: %s: singular post type label */
				__( 'Add New %s', 'vl-lms' ),
				$singular
			),
			'edit_item'             => sprintf(
				/* translators: %s: singular post type label */
				__( 'Edit %s', 'vl-lms' ),
				$singular
			),
			'new_item'              => sprintf(
				/* translators: %s: singular post type label */
				__( 'New %s', 'vl-lms' ),
				$singular
			),
			'view_item'             => sprintf(
				/* translators: %s: singular post type label */
				__( 'View %s', 'vl-lms' ),
				$singular
			),
			'search_items'          => sprintf(
				/* translators: %s: plural post type label */
				__( 'Search %s', 'vl-lms' ),
				$plural
			),
			'not_found'             => sprintf(
				/* translators: %s: plural post type label (lowercase) */
				__( 'No %s found.', 'vl-lms' ),
				strtolower( $plural )
			),
			'not_found_in_trash'    => sprintf(
				/* translators: %s: plural post type label (lowercase) */
				__( 'No %s found in Trash.', 'vl-lms' ),
				strtolower( $plural )
			),
			'archives'              => sprintf(
				/* translators: %s: singular post type label */
				__( '%s Archives', 'vl-lms' ),
				$singular
			),
			'insert_into_item'      => sprintf(
				/* translators: %s: singular post type label (lowercase) */
				__( 'Insert into %s', 'vl-lms' ),
				strtolower( $singular )
			),
			'uploaded_to_this_item' => sprintf(
				/* translators: %s: singular post type label (lowercase) */
				__( 'Uploaded to this %s', 'vl-lms' ),
				strtolower( $singular )
			),
			'featured_image'        => __( 'Featured image', 'vl-lms' ),
			'set_featured_image'    => __( 'Set featured image', 'vl-lms' ),
			'remove_featured_image' => __( 'Remove featured image', 'vl-lms' ),
			'use_featured_image'    => __( 'Use as featured image', 'vl-lms' ),
			'filter_items_list'     => sprintf(
				/* translators: %s: plural post type label (lowercase) */
				__( 'Filter %s list', 'vl-lms' ),
				strtolower( $plural )
			),
			'items_list_navigation' => sprintf(
				/* translators: %s: plural post type label */
				__( '%s list navigation', 'vl-lms' ),
				$plural
			),
			'items_list'            => sprintf(
				/* translators: %s: plural post type label */
				__( '%s list', 'vl-lms' ),
				$plural
			),
			'attributes'            => sprintf(
				/* translators: %s: singular post type label */
				__( '%s attributes', 'vl-lms' ),
				$singular
			),
			'parent_item_colon'     => sprintf(
				/* translators: %s: singular post type label */
				__( 'Parent %s:', 'vl-lms' ),
				$singular
			),
		];
	}
}
