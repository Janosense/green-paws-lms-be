<?php

declare(strict_types=1);

namespace VL\LMS\Orders\Exception;

/**
 * Raised when no order matches the supplied uuid. Mapped to
 * `404 order_not_found` by the REST controller.
 *
 * @author Tymofii Synianskyi
 */
class OrderNotFoundException extends \RuntimeException {
}
