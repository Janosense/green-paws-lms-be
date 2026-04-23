<?php

declare(strict_types=1);

namespace VL\LMS\Taxonomy;

/**
 * Registers the `vl_category` hierarchical taxonomy.
 *
 * Describes *what* a course or webinar is about (e.g. Cardiology, Surgery
 * → Orthopedics). Hierarchical so editors can nest specialisms under
 * broader domains. Attached to both `vl_course` and `vl_webinar`; lessons
 * inherit their parent course's categories implicitly.
 *
 * Terms are managed via WP's default capability map (`manage_categories`),
 * so administrators can create/edit/delete freely and instructors can
 * assign existing terms to their own content without being able to edit
 * the shared vocabulary.
 *
 * @author Tymofii Synianskyi
 */
final class CategoryTaxonomy extends AbstractTaxonomyRegistrar {

	protected function taxonomy(): string {
		return 'vl_category';
	}

	protected function object_types(): array {
		return [ 'vl_course', 'vl_webinar' ];
	}

	protected function singular_label(): string {
		return 'Category';
	}

	protected function plural_label(): string {
		return 'Categories';
	}

	protected function hierarchical(): bool {
		return true;
	}

	protected function capabilities(): ?array {
		return null;
	}
}
