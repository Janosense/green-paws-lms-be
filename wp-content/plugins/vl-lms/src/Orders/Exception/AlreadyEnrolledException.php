<?php

declare(strict_types=1);

namespace VL\LMS\Orders\Exception;

/**
 * Raised when a learner tries to purchase a course they already have an
 * active enrollment for. Mapped to `409 already_enrolled` by the REST
 * controller; the controller surfaces `existing_enrollment_id()` in the
 * error `data` payload for diagnosability.
 *
 * @author Tymofii Synianskyi
 */
class AlreadyEnrolledException extends \RuntimeException {

	public function __construct(
		private readonly int $existing_enrollment_id,
		string $message = 'Learner already has an active enrollment for this course.'
	) {
		parent::__construct( $message );
	}

	public function existing_enrollment_id(): int {
		return $this->existing_enrollment_id;
	}
}
