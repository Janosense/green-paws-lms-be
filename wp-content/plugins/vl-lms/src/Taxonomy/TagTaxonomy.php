<?php

declare(strict_types=1);

namespace VL\LMS\Taxonomy;

/**
 * Registers the `vl_tag` free-form flat taxonomy.
 *
 * Used for fine-grained labelling across `vl_course`, `vl_webinar`, and
 * `vl_lesson`. Lessons are included here (unlike the three other
 * taxonomies) because tagging at lesson granularity is the main way
 * authors surface cross-course keywords such as "x-ray", "ECG", or
 * "suturing".
 *
 * Uses WP's default capability map: administrators and any role with
 * `manage_categories` can curate the vocabulary; instructors assign
 * existing tags to their content.
 *
 * @author Tymofii Synianskyi
 */
final class TagTaxonomy extends AbstractTaxonomyRegistrar {

	protected function taxonomy(): string {
		return 'vl_tag';
	}

	protected function object_types(): array {
		return [ 'vl_course', 'vl_webinar', 'vl_lesson' ];
	}

	protected function singular_label(): string {
		return 'Tag';
	}

	protected function plural_label(): string {
		return 'Tags';
	}

	protected function hierarchical(): bool {
		return false;
	}

	protected function capabilities(): ?array {
		return null;
	}
}
