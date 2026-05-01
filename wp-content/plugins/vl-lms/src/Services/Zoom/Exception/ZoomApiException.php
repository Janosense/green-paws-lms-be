<?php

declare(strict_types=1);

namespace VL\LMS\Services\Zoom\Exception;

/**
 * HTTP-level errors from the Zoom REST API. Carries the raw HTTP status
 * plus, when available, Zoom's `code` / `message` and the parsed
 * response body so upstream services can switch on the error shape
 * (e.g. 404 on a delete is treated as already-deleted).
 *
 * @author Tymofii Synianskyi
 */
class ZoomApiException extends ZoomException {

	/**
	 * @param array<string, mixed>|null $response_body
	 */
	public function __construct(
		string $message,
		public readonly int $http_status,
		public readonly ?string $zoom_code = null,
		public readonly ?string $zoom_message = null,
		public readonly ?array $response_body = null,
		?\Throwable $previous = null
	) {
		parent::__construct( $message, 0, $previous );
	}
}
