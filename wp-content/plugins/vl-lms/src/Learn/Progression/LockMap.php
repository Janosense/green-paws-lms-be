<?php

declare(strict_types=1);

namespace VL\LMS\Learn\Progression;

/**
 * Keyed lookup of per-entity lock state for one user/course pair.
 *
 * Mirrors the {@see \VL\LMS\Learn\ProgressOverlay} /
 * {@see \VL\LMS\Learn\QuizStatusOverlay} pattern: built once at the top
 * of a curriculum walk, read O(1) per leaf, so no lock question ever
 * fans out into a query mid-walk.
 *
 * {@see self::empty()} is the "no gating applies" value and is what the
 * fast path returns for the overwhelming majority of courses — every
 * lookup against it answers `null`.
 *
 * @author Tymofii Synianskyi
 */
final class LockMap {

	/**
	 * @param array<string, LockState> $by_key Keyed by `{kind}:{id}`.
	 */
	private function __construct(
		private readonly array $by_key
	) {
	}

	/**
	 * @param array<string, LockState> $by_key
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Matches the sibling overlays' fromMap() / fromList() naming.
	public static function fromArray( array $by_key ): self {
		return new self( $by_key );
	}

	public static function empty(): self {
		return new self( [] );
	}

	public function is_empty(): bool {
		return [] === $this->by_key;
	}

	public function for_stop( CurriculumStop $stop ): ?LockState {
		return $this->by_key[ $stop->key() ] ?? null;
	}

	/**
	 * `$kind` is one of the {@see CurriculumStop} `KIND_*` constants.
	 */
	public function for_entity( string $kind, int $id ): ?LockState {
		return $this->by_key[ $kind . ':' . $id ] ?? null;
	}

	/**
	 * Serialized lock for a curriculum node, or `null` when open.
	 *
	 * @return array<string, mixed>|null
	 */
	public function to_node_value( string $kind, int $id ): ?array {
		return $this->for_entity( $kind, $id )?->to_array();
	}
}
