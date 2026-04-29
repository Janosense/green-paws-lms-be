<?php

declare(strict_types=1);

namespace VL\LMS\Services\Progress;

use VL\LMS\Domain\Progress\EntityType;
use VL\LMS\Domain\Progress\ViewEventType;

/**
 * Validated request DTO for `POST /vl/v1/progress`.
 *
 * Constructor accepts already-validated primitives. The
 * {@see self::fromArray()} factory is the single entry point that performs
 * shape, type, and bounds validation; on failure it throws
 * `\InvalidArgumentException` whose message is a stable, parse-friendly code
 * (e.g. `missing_field:entity_type`, `invalid_uuid`, `payload_too_large`)
 * that the controller lifts into a typed `WP_Error` response.
 *
 * `module` is rejected at the entity-type boundary — modules don't receive
 * direct events; they're written only by the completion fan-up.
 *
 * @author Tymofii Synianskyi
 */
final class ProgressEventRequest {

	/**
	 * Maximum size, in bytes, of the JSON-encoded `payload` field. Defends
	 * against runaway client payloads even though the storage column is
	 * `LONGTEXT` and could technically hold more.
	 */
	public const int PAYLOAD_MAX_BYTES = 4096;

	private const string UUID_REGEX = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

	/**
	 * @param array<string, mixed>|null $payload
	 */
	public function __construct(
		public readonly EntityType $entity_type,
		public readonly int $entity_id,
		public readonly string $session_uuid,
		public readonly ViewEventType $event_type,
		public readonly ?int $position_seconds,
		public readonly ?array $payload
	) {
	}

	/**
	 * Build a request DTO from the JSON body that the REST controller pulled
	 * via `WP_REST_Request::get_json_params()`.
	 *
	 * @param array<string, mixed> $raw
	 *
	 * @throws \InvalidArgumentException With a stable code on the first
	 *                                   validation failure.
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Mirrors enum factory naming convention.
	public static function fromArray( array $raw ): self {
		$entity_type_raw = self::require_string( $raw, 'entity_type' );
		if ( 'lesson' !== $entity_type_raw && 'topic' !== $entity_type_raw ) {
			// `module` is the obvious near-miss; reject it here so the
			// controller can emit `invalid_payload` rather than letting it
			// flow into the service and surface via a different path.
			throw new \InvalidArgumentException( 'invalid_entity_type' );
		}
		$entity_type = EntityType::from_string( $entity_type_raw );

		$entity_id = self::require_positive_int( $raw, 'entity_id' );

		$session_uuid = self::require_string( $raw, 'session_uuid' );
		if ( 1 !== preg_match( self::UUID_REGEX, $session_uuid ) ) {
			throw new \InvalidArgumentException( 'invalid_uuid' );
		}

		$event_type_raw = self::require_string( $raw, 'event_type' );
		$event_type     = ViewEventType::tryFrom( $event_type_raw );
		if ( null === $event_type ) {
			throw new \InvalidArgumentException( 'invalid_event_type' );
		}

		$position_seconds = self::optional_non_negative_int( $raw, 'position_seconds' );

		$payload = self::optional_payload( $raw );

		return new self( $entity_type, $entity_id, $session_uuid, $event_type, $position_seconds, $payload );
	}

	/**
	 * @param array<string, mixed> $raw
	 */
	private static function require_string( array $raw, string $field ): string {
		if ( ! array_key_exists( $field, $raw ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Stable developer-facing code, never user-rendered.
			throw new \InvalidArgumentException( 'missing_field:' . $field );
		}
		$value = $raw[ $field ];
		if ( ! is_string( $value ) || '' === $value ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Stable developer-facing code, never user-rendered.
			throw new \InvalidArgumentException( 'invalid_type:' . $field );
		}
		return $value;
	}

	/**
	 * @param array<string, mixed> $raw
	 */
	private static function require_positive_int( array $raw, string $field ): int {
		if ( ! array_key_exists( $field, $raw ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Stable developer-facing code, never user-rendered.
			throw new \InvalidArgumentException( 'missing_field:' . $field );
		}
		$value = $raw[ $field ];
		if ( ! is_int( $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Stable developer-facing code, never user-rendered.
			throw new \InvalidArgumentException( 'invalid_type:' . $field );
		}
		if ( $value <= 0 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Stable developer-facing code, never user-rendered.
			throw new \InvalidArgumentException( 'invalid_range:' . $field );
		}
		return $value;
	}

	/**
	 * @param array<string, mixed> $raw
	 */
	private static function optional_non_negative_int( array $raw, string $field ): ?int {
		if ( ! array_key_exists( $field, $raw ) ) {
			return null;
		}
		$value = $raw[ $field ];
		if ( null === $value ) {
			return null;
		}
		if ( ! is_int( $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Stable developer-facing code, never user-rendered.
			throw new \InvalidArgumentException( 'invalid_type:' . $field );
		}
		if ( $value < 0 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Stable developer-facing code, never user-rendered.
			throw new \InvalidArgumentException( 'invalid_range:' . $field );
		}
		return $value;
	}

	/**
	 * @param array<string, mixed> $raw
	 *
	 * @return array<string, mixed>|null
	 */
	private static function optional_payload( array $raw ): ?array {
		if ( ! array_key_exists( 'payload', $raw ) ) {
			return null;
		}
		$value = $raw['payload'];
		if ( null === $value ) {
			return null;
		}
		if ( ! is_array( $value ) ) {
			throw new \InvalidArgumentException( 'invalid_type:payload' );
		}

		// Reject lists outright — `payload` is documented as an object.
		// `array_is_list` is true for empty arrays as well; treat `[]` as
		// a valid (object-shaped) empty payload.
		if ( [] !== $value && array_is_list( $value ) ) {
			throw new \InvalidArgumentException( 'invalid_type:payload' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- size-only validation, never persisted.
		$encoded = json_encode( $value );
		if ( false === $encoded ) {
			throw new \InvalidArgumentException( 'invalid_type:payload' );
		}
		if ( strlen( $encoded ) > self::PAYLOAD_MAX_BYTES ) {
			throw new \InvalidArgumentException( 'payload_too_large' );
		}

		/** @var array<string, mixed> $value */
		return $value;
	}
}
