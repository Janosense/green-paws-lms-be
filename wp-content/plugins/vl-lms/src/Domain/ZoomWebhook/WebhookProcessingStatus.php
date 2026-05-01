<?php

declare(strict_types=1);

namespace VL\LMS\Domain\ZoomWebhook;

/**
 * Processing state of a `{prefix}vl_zoom_webhook_events` row.
 *
 * `PENDING` is the start state set by the receiver; the dispatcher (Phase
 * 7.2) advances to `PROCESSED`, `FAILED`, or `IGNORED` (the latter for
 * duplicate `tracking_id` short-circuits and unrecognized event types).
 *
 * @author Tymofii Synianskyi
 */
enum WebhookProcessingStatus: string {

	case PENDING   = 'pending';
	case PROCESSED = 'processed';
	case FAILED    = 'failed';
	case IGNORED   = 'ignored';

	/**
	 * Strict parser. Rejects unknown values with a descriptive exception so
	 * callers never silently mis-type a row.
	 *
	 * @throws \InvalidArgumentException When `$value` is not a recognized case.
	 */
	public static function from_string( string $value ): self {
		$case = self::tryFrom( $value );
		if ( null === $case ) {
			$options = implode( ', ', array_map( static fn ( self $c ): string => $c->value, self::cases() ) );
			throw new \InvalidArgumentException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception, message never surfaces to end users.
				sprintf( 'Unknown webhook processing status "%s". Valid options: %s.', $value, $options )
			);
		}
		return $case;
	}
}
