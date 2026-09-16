<?php

declare(strict_types=1);

namespace VL\LMS\StudyTime\Services\Exception;

/**
 * A heartbeat the service refused, carrying the reason code the REST layer
 * turns into a status (`StudyTime\Api\StudyTimeController`). The service
 * never builds a `WP_Error` itself, like
 * {@see \VL\LMS\Services\Assignments\Exception\AssignmentSubmissionFailedException}.
 *
 * @author Tymofii Synianskyi
 */
class HeartbeatFailedException extends \RuntimeException {

	public const string ENTITY_NOT_FOUND = 'entity_not_found';
	public const string NOT_ENROLLED     = 'not_enrolled';

	public readonly string $error_code;

	public function __construct(
		string $error_code,
		string $message = '',
		?\Throwable $previous = null
	) {
		$this->error_code = $error_code;
		parent::__construct( '' === $message ? $error_code : $message, 0, $previous );
	}
}
