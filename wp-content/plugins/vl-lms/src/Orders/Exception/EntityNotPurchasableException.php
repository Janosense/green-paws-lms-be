<?php

declare(strict_types=1);

namespace VL\LMS\Orders\Exception;

/**
 * Raised when the resolved entity is missing a price meta or its price is
 * zero. Mapped to `409 entity_not_purchasable` by the REST controller.
 *
 * @author Tymofii Synianskyi
 */
class EntityNotPurchasableException extends \RuntimeException {
}
