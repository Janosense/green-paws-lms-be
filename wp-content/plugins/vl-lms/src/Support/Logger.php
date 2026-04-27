<?php

declare(strict_types=1);

namespace VL\LMS\Support;

/**
 * Thin PSR-3-shaped logger.
 *
 * Writes through `error_log()` so output lands wherever the PHP/WordPress
 * runtime is configured to send it (WP_DEBUG_LOG, syslog, stderr, etc.).
 * Replaceable with Monolog or similar without touching callers.
 */
class Logger {

	public function __construct(
		private readonly string $channel = 'vl-lms',
	) {
	}

	/**
	 * @param array<string, mixed> $context
	 */
	public function debug( string $message, array $context = [] ): void {
		$this->log( 'debug', $message, $context );
	}

	/**
	 * @param array<string, mixed> $context
	 */
	public function info( string $message, array $context = [] ): void {
		$this->log( 'info', $message, $context );
	}

	/**
	 * @param array<string, mixed> $context
	 */
	public function warning( string $message, array $context = [] ): void {
		$this->log( 'warning', $message, $context );
	}

	/**
	 * @param array<string, mixed> $context
	 */
	public function error( string $message, array $context = [] ): void {
		$this->log( 'error', $message, $context );
	}

	/**
	 * @param array<string, mixed> $context
	 */
	private function log( string $level, string $message, array $context ): void {
		$suffix = empty( $context ) ? '' : ' ' . wp_json_encode( $context );
		error_log( sprintf( '[%s] %s: %s%s', $this->channel, strtoupper( $level ), $message, $suffix ) );
	}
}
