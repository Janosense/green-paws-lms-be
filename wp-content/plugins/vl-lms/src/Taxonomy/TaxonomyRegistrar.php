<?php

declare(strict_types=1);

namespace VL\LMS\Taxonomy;

/**
 * Aggregates per-taxonomy registrars and wires them to WordPress.
 *
 * Owns the canonical, ordered list of every VL LMS taxonomy. Hooks into
 * `init` at priority 10; the CPT registrar hooks the same action at the
 * same priority, and WP runs callbacks in registration order, so post
 * types are registered before the taxonomies that attach to them.
 *
 * Direct instantiation mirrors `CptRegistrar`: the list is short, fixed
 * at declaration time, and has no external configuration.
 *
 * @author Tymofii Synianskyi
 */
final class TaxonomyRegistrar {

	/**
	 * @var list<AbstractTaxonomyRegistrar>
	 */
	private array $registrars;

	public function __construct() {
		$this->registrars = [
			new CategoryTaxonomy(),
			new SpecialtyTaxonomy(),
			new DifficultyTaxonomy(),
			new TagTaxonomy(),
		];
	}

	public function register_hooks(): void {
		add_action( 'init', [ $this, 'register_all' ], 10 );
	}

	public function register_all(): void {
		foreach ( $this->registrars as $registrar ) {
			$registrar->register();
		}
	}

	/**
	 * @return list<AbstractTaxonomyRegistrar>
	 */
	public function registrars(): array {
		return $this->registrars;
	}
}
