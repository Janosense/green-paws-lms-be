<?php

declare(strict_types=1);

namespace VL\LMS\Orders\Exception;

/**
 * Raised when a learner tries to purchase a webinar they are already
 * actively registered for. Mapped to `409 already_registered` by the REST
 * controller.
 *
 * @author Tymofii Synianskyi
 */
class AlreadyRegisteredException extends \RuntimeException {

	public function __construct(
		private readonly int $existing_registration_id,
		string $message = 'Learner already has an active registration for this webinar.'
	) {
		parent::__construct( $message );
	}

	public function existing_registration_id(): int {
		return $this->existing_registration_id;
	}
}
