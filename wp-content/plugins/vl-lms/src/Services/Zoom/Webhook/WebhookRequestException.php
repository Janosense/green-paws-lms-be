<?php

declare(strict_types=1);

namespace VL\LMS\Services\Zoom\Webhook;

/**
 * Carrier for {@see WebhookRequestParser} validation failures.
 *
 * The exception's `getCode()` returns a stable string discriminator
 * (e.g. `invalid_json`, `missing_event`) so the controller can return
 * the failure mode in its 400 response without coupling to the message.
 *
 * @author Tymofii Synianskyi
 */
final class WebhookRequestException extends \RuntimeException {

	private string $reason_code;

	public function __construct( string $reason_code, string $message ) {
		parent::__construct( $message );
		$this->reason_code = $reason_code;
	}

	public function reason_code(): string {
		return $this->reason_code;
	}
}
