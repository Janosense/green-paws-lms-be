<?php

declare(strict_types=1);

namespace VL\LMS\Cli;

/**
 * Shared run-time context handed to every sub-seeder.
 *
 * Carries the WP environment type (so `production` can be guarded), the
 * `--force` flag, a deterministic integer seed used to derive Picsum stable
 * keys and progress sequences, and a logger callable so sub-seeders can
 * surface progress messages without referring to `WP_CLI::*` directly.
 *
 * Determinism is the whole point of `seed`: the same integer must produce
 * identical content (titles, video URLs, completed-leaf selections) on every
 * re-run so re-running `wp vl-lms demo seed` is genuinely idempotent.
 *
 * @author Tymofii Synianskyi
 */
final class SeederContext {

	/** @var callable(string): void */
	private $logger;

	/**
	 * @param callable(string): void $logger Called with already-formatted lines for `WP_CLI::log()`.
	 */
	public function __construct(
		public readonly string $environment_type,
		public readonly bool $force,
		public readonly bool $skip_progress,
		public readonly int $seed,
		callable $logger
	) {
		$this->logger = $logger;
	}

	public function log( string $message ): void {
		( $this->logger )( $message );
	}
}
