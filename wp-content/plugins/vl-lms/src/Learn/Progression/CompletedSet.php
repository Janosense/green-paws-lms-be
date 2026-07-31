<?php

declare(strict_types=1);

namespace VL\LMS\Learn\Progression;

/**
 * The set of curriculum stops a learner has completed, keyed by
 * {@see CurriculumStop::key()} format (`lesson:5`, `topic:7`).
 *
 * This is the sequential rule's third input, shaped so that
 * {@see ProgressionPolicy} stays pure: the gate maps `vl_progress` rows
 * to keys, and the policy only ever asks membership questions — no
 * `Domain\Progress` imports, no status enum, no row objects.
 *
 * @author Tymofii Synianskyi
 */
final class CompletedSet {

	/**
	 * @param array<string, true> $keys
	 */
	private function __construct( private readonly array $keys ) {
	}

	/**
	 * @param list<string> $keys
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Matches the sibling overlays' fromMap() / fromArray() naming.
	public static function fromKeys( array $keys ): self {
		return new self( array_fill_keys( $keys, true ) );
	}

	public function has( string $key ): bool {
		return isset( $this->keys[ $key ] );
	}
}
