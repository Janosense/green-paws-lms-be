<?php

declare(strict_types=1);

namespace VL\LMS\Repositories;

/**
 * Thrown by {@see PaymentRepository::insert()} when the row's
 * `idempotency_key` collides with an existing payment.
 *
 * Phase 8.2's `CallbackHandler` always re-checks via
 * {@see PaymentRepository::find_by_idempotency_key()} before inserting, so
 * this exception only surfaces when two concurrent requests race past the
 * lookup and both reach INSERT — the UNIQUE constraint is the safety
 * backstop. Callers should catch and treat as success ("the other request
 * already recorded this payment").
 *
 * @author Tymofii Synianskyi
 */
final class PaymentAlreadyRecordedException extends \RuntimeException {

	public function __construct( string $idempotency_key ) {
		parent::__construct(
			sprintf( 'A payment with idempotency_key "%s" already exists.', $idempotency_key )
		);
	}
}
