<?php

declare(strict_types=1);

namespace VL\LMS\CPT;

/**
 * Registers the `vl_module` custom post type.
 *
 * Modules are an optional grouping layer inside self-paced courses: each
 * module attaches to a `vl_course` via `post_parent` and can itself host
 * lessons, quizzes, and assignments as its own children. Modules do not
 * nest (`hierarchical: false`) — ordering inside a course uses the default
 * `menu_order` field exposed through `page-attributes`.
 *
 * The CPT is hidden from `wp/v2` — the Nuxt frontend consumes modules via
 * the `vl/v1/*` controllers. The business rule that modules only belong
 * under self-paced courses is enforced in services and REST controllers,
 * not at the CPT level.
 *
 * @author Tymofii Synianskyi
 */
final class ModuleType extends AbstractCptRegistrar {

	protected function post_type(): string {
		return 'vl_module';
	}

	protected function singular_label(): string {
		return 'Module';
	}

	protected function plural_label(): string {
		return 'Modules';
	}

	protected function capability_type(): array {
		return [ 'vl_module', 'vl_modules' ];
	}

	protected function supports(): array {
		return [ 'title', 'editor', 'thumbnail', 'custom-fields', 'page-attributes' ];
	}

	protected function menu_icon(): string {
		return 'dashicons-portfolio';
	}

	protected function hierarchical(): bool {
		return false;
	}

	protected function show_in_menu(): bool {
		return true;
	}

	/**
	 * Modules carry no custom meta of their own — title, editor body,
	 * featured image, and `menu_order` cover the grouping use case.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected function meta_fields(): array {
		return [];
	}
}
