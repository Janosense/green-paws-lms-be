<?php

declare(strict_types=1);

namespace VL\LMS\Certificate;

use VL\LMS\Domain\Certificate\Certificate;

/**
 * Service-level outcome of {@see CertificateService::issue_for_enrollment()}.
 *
 * Four valid shapes:
 *
 * - **Issued (new):** `success=true, skipped=false, idempotent=false, certificate=Certificate`.
 * - **Idempotent (existing):** `success=true, skipped=false, idempotent=true, certificate=Certificate`.
 * - **Skipped (certificates disabled on course):** `success=false, skipped=true, idempotent=false, certificate=null, error_code='certificates_disabled'`.
 * - **Failure:** `success=false, skipped=false, idempotent=false, certificate=null, error_code=...`.
 *
 * The `skipped` branch is intentionally distinct from the failure branch:
 * the auto-issuer treats `skipped=true` as an everyday no-op (course
 * doesn't have certificates enabled) and does not log it as an error.
 *
 * @author Tymofii Synianskyi
 */
final readonly class IssueResult {

	public function __construct(
		public bool $success,
		public bool $skipped,
		public bool $idempotent,
		public ?Certificate $certificate,
		public ?string $error_code,
		public ?string $error_message
	) {
	}

	public static function issued( Certificate $cert ): self {
		return new self( true, false, false, $cert, null, null );
	}

	public static function idempotent( Certificate $cert ): self {
		return new self( true, false, true, $cert, null, null );
	}

	public static function skipped( string $code, string $message ): self {
		return new self( false, true, false, null, $code, $message );
	}

	public static function failure( string $code, string $message ): self {
		return new self( false, false, false, null, $code, $message );
	}
}
