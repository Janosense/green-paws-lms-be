<?php

declare(strict_types=1);

namespace VL\LMS\Services;

/**
 * Shared join-window policy used by both the webinar and session access
 * gates.
 *
 * Codifies the Zoom-conventional "join 15 minutes early, stay 60 minutes
 * past scheduled end" pattern. Kept as an immutable VO so the two gates
 * inject the same instance from the container; tests can substitute their
 * own to pin alternate boundaries.
 *
 * @author Tymofii Synianskyi
 */
final readonly class JoinWindowPolicy {

	public function __construct(
		public int $early_grace_minutes = 15,
		public int $late_grace_minutes = 60,
	) {
	}

	/**
	 * Compute `[opens_at, closes_at]` for a scheduled `[$start, $end]`
	 * pair, applying the grace constants on either side.
	 *
	 * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
	 */
	public function compute_window( \DateTimeImmutable $start, \DateTimeImmutable $end ): array {
		$opens_at  = $start->modify( '-' . ( $this->early_grace_minutes * 60 ) . ' seconds' );
		$closes_at = $end->modify( '+' . ( $this->late_grace_minutes * 60 ) . ' seconds' );
		return [ $opens_at, $closes_at ];
	}
}
