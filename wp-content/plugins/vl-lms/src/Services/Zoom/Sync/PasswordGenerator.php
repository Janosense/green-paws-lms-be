<?php

declare(strict_types=1);

namespace VL\LMS\Services\Zoom\Sync;

/**
 * Generates Zoom meeting passwords.
 *
 * Zoom allows up to 10 characters and accepts the alphanumeric set
 * unconditionally — we stick to `[A-Za-z0-9]` so participants who type
 * the password manually never trip a special-character mismatch.
 *
 * Concrete (not final) so unit tests can subclass and pin the random
 * source without touching `random_int`.
 *
 * @author Tymofii Synianskyi
 */
class PasswordGenerator {

	private const string ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

	private const int LENGTH = 10;

	/**
	 * Returns a 10-character alphanumeric password drawn from a CSPRNG.
	 *
	 * @throws \RuntimeException When the platform CSPRNG fails.
	 */
	public function generate(): string {
		$max = strlen( self::ALPHABET ) - 1;
		$out = '';
		for ( $i = 0; $i < self::LENGTH; $i++ ) {
			$out .= self::ALPHABET[ $this->random_index( 0, $max ) ];
		}
		return $out;
	}

	/**
	 * Indirected so unit tests can subclass and pin the random source.
	 *
	 * @throws \RuntimeException When the platform CSPRNG fails.
	 */
	protected function random_index( int $min, int $max ): int {
		try {
			return random_int( $min, $max );
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception.
			throw new \RuntimeException( 'Failed to generate Zoom password: ' . $e->getMessage(), 0, $e );
		}
	}
}
