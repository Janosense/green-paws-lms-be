<?php

declare(strict_types=1);

namespace VL\LMS\Orders\Exception;

/**
 * Raised when a learner tries to purchase a webinar that has reached its
 * configured capacity. Pre-validation by `OrderService::create_for_purchase`
 * closes most of the pay-then-can't-register hole; the residual TOCTOU
 * window is handled by Phase 8.3's refund flow if it ever fires.
 *
 * Mapped to `409 webinar_full` by the REST controller.
 *
 * @author Tymofii Synianskyi
 */
class WebinarFullException extends \RuntimeException {
}
