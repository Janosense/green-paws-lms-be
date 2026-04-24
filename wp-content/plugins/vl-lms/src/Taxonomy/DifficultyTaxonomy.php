<?php

declare(strict_types=1);

namespace VL\LMS\Taxonomy;

/**
 * Registers the `vl_difficulty` flat taxonomy with a fixed vocabulary.
 *
 * Exactly three terms exist — basic / advanced / expert — and the set is
 * deliberately closed. Instructors must never be able to invent new
 * difficulty values, so term management is tied to `manage_options`
 * (administrators only). Assignment is tied to `edit_posts`, which every
 * role that can edit content already has.
 *
 * The default terms themselves are not inserted here; that happens in the
 * activator task so it can run inside `register_activation_hook`. The
 * canonical list lives on this class as `DEFAULT_TERMS` so the activator
 * imports it without duplicating the vocabulary.
 *
 * @author Tymofii Synianskyi
 */
final class DifficultyTaxonomy extends AbstractTaxonomyRegistrar {

	/**
	 * Canonical default terms for `vl_difficulty`.
	 *
	 * Keys are term slugs, values are the untranslated English labels.
	 *
	 * @var array<string, string>
	 */
	public const array DEFAULT_TERMS = [
		'basic'    => 'Basic',
		'advanced' => 'Advanced',
		'expert'   => 'Expert',
	];

	// Default terms (DEFAULT_TERMS) are inserted by the final activator task, not here.

	protected function taxonomy(): string {
		return 'vl_difficulty';
	}

	protected function object_types(): array {
		return [ 'vl_course', 'vl_webinar' ];
	}

	protected function singular_label(): string {
		return 'Difficulty';
	}

	protected function plural_label(): string {
		return 'Difficulties';
	}

	protected function hierarchical(): bool {
		return false;
	}

	protected function capabilities(): ?array {
		return [
			'manage_terms' => 'manage_options',
			'edit_terms'   => 'manage_options',
			'delete_terms' => 'manage_options',
			'assign_terms' => 'edit_posts',
		];
	}
}
