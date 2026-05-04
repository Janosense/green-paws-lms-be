<?php

declare(strict_types=1);

namespace VL\LMS\Payments\Exception;

/**
 * Raised when a {@see \VL\LMS\Payments\PaymentProvider} cannot prepare a
 * payment because its configuration is incomplete (missing public/private
 * keys for LiqPay, etc.). Mapped to `503 payment_provider_unavailable` by
 * the REST controller.
 *
 * @author Tymofii Synianskyi
 */
class PaymentProviderUnavailableException extends \RuntimeException {
}
