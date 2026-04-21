<?php

declare(strict_types=1);

namespace VLJwtAuth\Support;

/**
 * Hashing utility used to derive the DB lookup key for refresh tokens.
 *
 * Returns 64 lowercase hex characters — matches the CHAR(64) ascii_bin
 * column in {prefix}vl_refresh_tokens.
 */
final class Hasher {

	public static function hash( string $value ): string {
		return hash( 'sha256', $value );
	}
}
