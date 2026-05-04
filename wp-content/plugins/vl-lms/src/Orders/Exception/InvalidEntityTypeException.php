<?php

declare(strict_types=1);

namespace VL\LMS\Orders\Exception;

/**
 * Raised when the REST body carries an `entity_type` value that is not in
 * the `course | webinar` set. Mapped to `400 invalid_entity_type` by the
 * REST controller.
 *
 * @author Tymofii Synianskyi
 */
class InvalidEntityTypeException extends \RuntimeException {
}
