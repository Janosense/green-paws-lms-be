<?php

declare(strict_types=1);

namespace VL\LMS\Cli;

/**
 * Mutable counter object returned by every sub-seeder.
 *
 * Sub-seeders increment the counters as they run; the orchestrator
 * aggregates results across the run and prints a one-line summary at the
 * end. Messages are kept verbatim for the orchestrator to forward to
 * `WP_CLI::log()` in a deterministic order.
 *
 * @author Tymofii Synianskyi
 */
final class SeederResult {

	public int $created = 0;
	public int $skipped = 0;
	public int $failed  = 0;

	/** @var list<string> */
	public array $messages = [];

	public function add( self $other ): void {
		$this->created += $other->created;
		$this->skipped += $other->skipped;
		$this->failed  += $other->failed;
		$this->messages = array_merge( $this->messages, $other->messages );
	}
}
