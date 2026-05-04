<?php

declare(strict_types=1);

namespace VL\LMS\Orders\Exception;

/**
 * Raised when a user tries to view or act on an order owned by a different
 * user. The REST controller deliberately maps this to `404 order_not_found`
 * (rather than `403 forbidden`) so non-owners cannot probe for the
 * existence of someone else's orders.
 *
 * @author Tymofii Synianskyi
 */
class OrderNotOwnedException extends \RuntimeException {
}
