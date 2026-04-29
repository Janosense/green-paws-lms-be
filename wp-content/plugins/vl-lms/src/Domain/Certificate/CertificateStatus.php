<?php

declare(strict_types=1);

namespace VL\LMS\Domain\Certificate;

/**
 * Active/revoked status of a {@see Certificate}.
 *
 * Derived at read time from the row's `revoked_at` column rather than
 * stored as its own column — `revoked_at IS NULL` is the canonical truth.
 * Surfaced as an enum so the rest of the codebase can switch on a typed
 * value rather than a nullable timestamp.
 *
 * @author Tymofii Synianskyi
 */
enum CertificateStatus: string {

	case ACTIVE  = 'active';
	case REVOKED = 'revoked';
}
