<?php

declare(strict_types=1);

namespace VL\LMS\Api;

use VL\LMS\Services\Zoom\Settings\ZoomSettingsProvider;
use VL\LMS\Services\Zoom\Webhook\UrlValidationResponder;
use VL\LMS\Services\Zoom\Webhook\WebhookEventDispatcher;
use VL\LMS\Services\Zoom\Webhook\WebhookRequestException;
use VL\LMS\Services\Zoom\Webhook\WebhookRequestParser;
use VL\LMS\Services\Zoom\Webhook\WebhookSignatureVerifier;
use VL\LMS\Support\Logger;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Public REST receiver for Zoom-side webhooks.
 *
 * Single endpoint:
 *
 *  - `POST /vl/v1/webhooks/zoom` — verifies the HMAC-SHA256 signature
 *    (5-minute replay window), parses the body, short-circuits the
 *    Marketplace `endpoint.url_validation` challenge, otherwise hands
 *    off to {@see WebhookEventDispatcher}.
 *
 * `permission_callback` is `__return_true`. Authenticity is enforced
 * inside `handle()` via {@see WebhookSignatureVerifier}.
 *
 * The controller never returns 5xx for downstream handler failures —
 * Zoom retries on non-2xx, and the dedup table is the single
 * retry-mitigation strategy.
 *
 * @author Tymofii Synianskyi
 */
class ZoomWebhookController {

	public const string ROUTE = '/webhooks/zoom';

	public function __construct(
		private readonly string $rest_namespace,
		private readonly WebhookSignatureVerifier $verifier,
		private readonly WebhookRequestParser $parser,
		private readonly UrlValidationResponder $responder,
		private readonly WebhookEventDispatcher $dispatcher,
		private readonly ZoomSettingsProvider $settings,
		private readonly Logger $logger
	) {
	}

	public function register_routes(): void {
		register_rest_route(
			$this->rest_namespace,
			self::ROUTE,
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handle' ],
				'permission_callback' => '__return_true',
			]
		);
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle( WP_REST_Request $request ) {
		$raw_body  = (string) $request->get_body();
		$signature = (string) $request->get_header( 'x-zm-signature' );
		$timestamp = (string) $request->get_header( 'x-zm-request-timestamp' );

		$verification = $this->verifier->verify( $raw_body, $signature, $timestamp );
		if ( ! $verification->valid ) {
			if ( 'missing_secret' === $verification->reason ) {
				$this->logger->error(
					'Zoom webhook secret is not configured; rejecting inbound webhook.',
					[ 'reason' => $verification->reason ]
				);
				return new WP_Error(
					'webhook_misconfigured',
					__( 'Zoom webhook secret is not configured.', 'vl-lms' ),
					[ 'status' => 401 ]
				);
			}

			$this->logger->warning(
				'Zoom webhook signature verification failed.',
				[ 'reason' => $verification->reason ]
			);
			return new WP_Error(
				'webhook_invalid_signature',
				__( 'Webhook signature verification failed.', 'vl-lms' ),
				[ 'status' => 401 ]
			);
		}

		try {
			$webhook_request = $this->parser->parse( $request );
		} catch ( WebhookRequestException $e ) {
			$this->logger->warning(
				'Zoom webhook payload could not be parsed.',
				[
					'reason'  => $e->reason_code(),
					'message' => $e->getMessage(),
				]
			);
			return new WP_Error(
				'webhook_invalid_payload',
				$e->reason_code(),
				[ 'status' => 400 ]
			);
		}

		if ( $webhook_request->is_url_validation() ) {
			$secret   = $this->settings->get_credentials()->webhook_secret;
			$body     = $this->responder->respond( $webhook_request->url_validation_plain_token, $secret );
			$response = rest_ensure_response( $body );
			$response->set_status( 200 );
			return $response;
		}

		$result = $this->dispatcher->dispatch( $webhook_request );

		$response = rest_ensure_response(
			[
				'success' => true,
				'status'  => $result->status->value,
				'message' => $result->message,
			]
		);
		$response->set_status( 200 );
		return $response;
	}
}
