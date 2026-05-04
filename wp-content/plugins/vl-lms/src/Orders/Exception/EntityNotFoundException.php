<?php

declare(strict_types=1);

namespace VL\LMS\Orders\Exception;

/**
 * Raised when {@see \VL\LMS\Orders\PurchasableLookup::find} cannot resolve
 * the slug to a published course or webinar. Mapped to
 * `404 entity_not_found` by the REST controller.
 *
 * @author Tymofii Synianskyi
 */
class EntityNotFoundException extends \RuntimeException {
}
