<?php

declare(strict_types=1);

namespace VL\LMS\Services\Zoom\Webhook;

use Closure;
use VL\LMS\Domain\ZoomWebhook\WebhookEventType;
use VL\LMS\Repositories\ZoomWebhookEventRepository;
use VL\LMS\Support\Logger;

/**
 * Persists, deduplicates, and routes parsed webhook envelopes to the
 * matching {@see EventHandler}.
 *
 * Algorithm:
 *  1. `find_by_tracking_id` — duplicates short-circuit as IGNORED.
 *  2. Unsupported event names (not in {@see WebhookEventType}) are
 *     recorded with status IGNORED.
 *  3. INSERT a `pending` row. On unique-constraint race, treat as duplicate.
 *  4. Resolve handler. Missing handler → IGNORED (defensive — covered
 *     by step 2 in practice).
 *  5. Invoke handler in try/catch. Success → mark_processed. Failure →
 *     mark_failed. NEVER re-throws.
 *
 * Concrete (not final) so unit tests can subclass.
 *
 * @author Tymofii Synianskyi
 */
class WebhookEventDispatcher {

	/** @var Closure(): \DateTimeImmutable */
	private Closure $clock;

	/**
	 * @param Closure(): \DateTimeImmutable $clock
	 */
	public function __construct(
		private readonly ZoomWebhookEventRepository $repository,
		private readonly HandlerRegistry $registry,
		private readonly Logger $logger,
		Closure $clock
	) {
		$this->clock = $clock;
	}

	public function dispatch( WebhookRequest $request ): DispatchResult {
		// 1. Idempotency — duplicate trackingid short-circuits.
		if ( '' !== $request->tracking_id ) {
			$existing = $this->repository->find_by_tracking_id( $request->tracking_id );
			if ( null !== $existing ) {
				return DispatchResult::ignored( 'duplicate_tracking_id' );
			}
		}

		// 2. Resolve the canonical event type.
		$event_type = WebhookEventType::from_string( $request->event );

		$now         = ( $this->clock )();
		$object_id   = $request->object_id();
		$object_id   = '' === $object_id ? null : $object_id;
		$payload_raw = $request->raw_body;

		if ( null === $event_type ) {
			$stored = $this->record_safely( $request, WebhookEventType::ENDPOINT_URL_VALIDATION, $object_id, $payload_raw, $now );
			if ( null === $stored ) {
				return DispatchResult::ignored( 'duplicate_tracking_id' );
			}
			$this->repository->mark_ignored( $stored, $now );
			return DispatchResult::ignored( 'unsupported_event' );
		}

		// 3. Insert pending row.
		$stored_id = $this->record_safely( $request, $event_type, $object_id, $payload_raw, $now );
		if ( null === $stored_id ) {
			return DispatchResult::ignored( 'duplicate_tracking_id' );
		}

		// 4. Resolve handler.
		$handler = $this->registry->find( $event_type );
		if ( null === $handler ) {
			$this->repository->mark_ignored( $stored_id, $now );
			return DispatchResult::ignored( 'no_handler_registered' );
		}

		// 5. Invoke handler.
		try {
			$outcome = $handler->handle( $request );
		} catch ( \Throwable $e ) {
			$this->logger->error(
				'Zoom webhook handler threw.',
				[
					'event'       => $request->event,
					'tracking_id' => $request->tracking_id,
					'class'       => $e::class,
					'message'     => $e->getMessage(),
				]
			);
			$this->repository->mark_failed( $stored_id, $e->getMessage(), $now );
			return DispatchResult::failed( $e );
		}

		$this->repository->mark_processed( $stored_id, $now );
		return DispatchResult::processed( $outcome );
	}

	/**
	 * Wraps {@see ZoomWebhookEventRepository::record()} so a duplicate-
	 * row race (two requests arriving within the same millisecond, both
	 * past the find_by_tracking_id check) is swallowed cleanly.
	 *
	 * Returns the stored event id, or `null` to signal "duplicate, treat
	 * as ignored".
	 */
	private function record_safely(
		WebhookRequest $request,
		WebhookEventType $event_type,
		?string $object_id,
		string $payload_raw,
		\DateTimeImmutable $received_at
	): ?int {
		try {
			$stored = $this->repository->record(
				$request->tracking_id,
				$event_type,
				$request->event_ts_ms,
				$object_id,
				$payload_raw,
				$received_at
			);
			return $stored->id;
		} catch ( \Throwable $e ) {
			$this->logger->warning(
				'Zoom webhook dedup: record() race short-circuited as ignored.',
				[
					'tracking_id' => $request->tracking_id,
					'class'       => $e::class,
					'message'     => $e->getMessage(),
				]
			);
			return null;
		}
	}
}
