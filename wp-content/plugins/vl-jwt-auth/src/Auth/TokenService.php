<?php

declare(strict_types=1);

namespace VLJwtAuth\Auth;

use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use VLJwtAuth\Exception\TokenException;
use WP_User;

/**
 * Thin wrapper around firebase/php-jwt: issue, decode, validate.
 *
 * Knows nothing about WordPress requests, cookies, or the database.
 * Depends only on a secret + the claim builder, so it can be unit-tested
 * in isolation or reused by non-REST callers (CLI tooling, cron, etc.).
 */
final class TokenService {

	private const ALGORITHM = 'HS256';

	public function __construct(
		private string $secret,
		private ClaimsBuilder $claims_builder
	) {
	}

	/**
	 * Sign a JWT for the given user and token type.
	 *
	 * @param 'access'|'refresh' $type
	 * @return array{token: string, jti: string, expires_at: int, claims: array<string, mixed>}
	 */
	public function issue( WP_User $user, string $type ): array {
		$claims = $this->claims_builder->build( $user, $type );
		$token  = JWT::encode( $claims, $this->secret, self::ALGORITHM );

		return [
			'token'      => $token,
			'jti'        => (string) ( $claims['jti'] ?? '' ),
			'expires_at' => (int) ( $claims['exp'] ?? 0 ),
			'claims'     => $claims,
		];
	}

	/**
	 * Decode and validate a JWT. Throws with a stable error code on failure.
	 *
	 * @return array<string, mixed>
	 * @throws TokenException
	 */
	public function decode( string $jwt ): array {
		try {
			$decoded = JWT::decode( $jwt, new Key( $this->secret, self::ALGORITHM ) );
		} catch ( ExpiredException $e ) {
			throw new TokenException( 'token_expired', $e->getMessage(), 401, $e );
		} catch ( SignatureInvalidException | BeforeValidException $e ) {
			throw new TokenException( 'token_invalid', $e->getMessage(), 401, $e );
		} catch ( \UnexpectedValueException | \DomainException | \InvalidArgumentException $e ) {
			throw new TokenException( 'token_invalid', $e->getMessage(), 401, $e );
		}

		// JWT::decode returns stdClass; deep-convert so callers see arrays all the way down
		// (e.g. the `roles` claim, which is an array at encode-time).
		$as_array = json_decode( (string) wp_json_encode( $decoded ), true );
		return is_array( $as_array ) ? $as_array : [];
	}

	/**
	 * Decode, and verify the token is marked as an access token.
	 *
	 * @return array<string, mixed>
	 * @throws TokenException
	 */
	public function decode_access( string $jwt ): array {
		$claims = $this->decode( $jwt );
		if ( 'access' !== ( $claims['type'] ?? null ) ) {
			throw new TokenException( 'token_invalid', 'Token is not an access token.', 401 );
		}
		return $claims;
	}

	/**
	 * Decode, and verify the token is marked as a refresh token.
	 *
	 * @return array<string, mixed>
	 * @throws TokenException
	 */
	public function decode_refresh( string $jwt ): array {
		$claims = $this->decode( $jwt );
		if ( 'refresh' !== ( $claims['type'] ?? null ) ) {
			throw new TokenException( 'refresh_token_invalid', 'Token is not a refresh token.', 401 );
		}
		return $claims;
	}
}
