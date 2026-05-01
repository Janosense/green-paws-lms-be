<?php

declare(strict_types=1);

namespace VL\LMS\Services\Zoom\Webhook;

/**
 * Computes the documented `endpoint.url_validation` response shape.
 *
 * Zoom's marketplace validation flow asks us to echo the inbound
 * `plainToken` plus its HMAC-SHA256 over the configured webhook secret.
 *
 * Concrete (not final) so unit tests can mock if ever needed; pure
 * stateless transform — no settings or DI in the constructor.
 *
 * @author Tymofii Synianskyi
 */
class UrlValidationResponder {

	/**
	 * @return array{plainToken: string, encryptedToken: string}
	 */
	public function respond( string $plain_token, string $webhook_secret ): array {
		return [
			'plainToken'     => $plain_token,
			'encryptedToken' => hash_hmac( 'sha256', $plain_token, $webhook_secret ),
		];
	}
}
