<?php

declare(strict_types=1);

namespace VL\LMS\Taxonomy;

/**
 * Registers the `vl_specialty` flat taxonomy.
 *
 * Describes *who* a course or webinar is aimed at — therapist, surgeon,
 * anesthesiologist, and similar professional roles. Flat because roles
 * do not form a natural hierarchy; a piece of content often targets
 * several roles and each should be an equally first-class tag.
 *
 * Uses WP's default capability map: administrators manage the vocabulary,
 * instructors assign from the existing list.
 *
 * @author Tymofii Synianskyi
 */
final class SpecialtyTaxonomy extends AbstractTaxonomyRegistrar {

	protected function taxonomy(): string {
		return 'vl_specialty';
	}

	protected function object_types(): array {
		return [ 'vl_course', 'vl_webinar' ];
	}

	protected function singular_label(): string {
		return 'Specialty';
	}

	protected function plural_label(): string {
		return 'Specialties';
	}

	protected function hierarchical(): bool {
		return false;
	}

	protected function capabilities(): ?array {
		return null;
	}
}
