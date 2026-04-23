<?php

declare(strict_types=1);

namespace VL\LMS\CPT;

/**
 * Registers the `vl_webinar` custom post type and its meta schema.
 *
 * A webinar is a standalone one-off online event. Unlike `vl_session`,
 * it is not attached to a course — it carries its own author, featured
 * image, description, price, and registration window. Users pay for and
 * register against the webinar post directly.
 *
 * Zoom fields mirror `vl_session` (meeting id, join/start URLs, password,
 * recording URL). Phase 1 fills them manually; Phase 1.5 introduces a
 * synchronizer service on top of the same schema.
 *
 * Recording access is controlled by a post-event window: the recording
 * URL stays available for `_vl_webinar_recording_access_days` after the
 * event. `0` means no recording is offered. The gate itself is enforced
 * in the REST layer, not the CPT.
 *
 * Hidden from `wp/v2` — the Nuxt frontend reads webinars through the
 * `vl/v1/*` controllers.
 *
 * @author Tymofii Synianskyi
 */
final class WebinarType extends AbstractCptRegistrar {

	protected function post_type(): string {
		return 'vl_webinar';
	}

	protected function singular_label(): string {
		return 'Webinar';
	}

	protected function plural_label(): string {
		return 'Webinars';
	}

	protected function capability_type(): array {
		return [ 'vl_webinar', 'vl_webinars' ];
	}

	protected function supports(): array {
		return [ 'title', 'editor', 'excerpt', 'thumbnail', 'author', 'custom-fields' ];
	}

	protected function menu_icon(): ?string {
		return 'dashicons-video-alt3';
	}

	protected function hierarchical(): bool {
		return false;
	}

	protected function show_in_menu(): bool {
		return true;
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	protected function meta_fields(): array {
		$auth = static fn ( bool $allowed, string $meta_key, int $object_id ): bool =>
			current_user_can( 'edit_post', $object_id );

		return [
			'_vl_webinar_scheduled_start'        => [
				'type'              => 'string',
				'single'            => true,
				'default'           => '',
				'show_in_rest'      => false,
				'sanitize_callback' => static fn ( mixed $v ): string => self::sanitize_iso8601( $v ),
				'auth_callback'     => $auth,
				'description'       => 'ISO 8601 UTC timestamp when the webinar starts.',
			],
			'_vl_webinar_scheduled_end'          => [
				'type'              => 'string',
				'single'            => true,
				'default'           => '',
				'show_in_rest'      => false,
				'sanitize_callback' => static fn ( mixed $v ): string => self::sanitize_iso8601( $v ),
				'auth_callback'     => $auth,
				'description'       => 'ISO 8601 UTC timestamp when the webinar ends.',
			],
			'_vl_webinar_status'                 => [
				'type'              => 'string',
				'single'            => true,
				'default'           => 'scheduled',
				'show_in_rest'      => false,
				'sanitize_callback' => static fn ( mixed $v ): string => self::sanitize_status( $v ),
				'auth_callback'     => $auth,
				'description'       => 'Lifecycle state — "scheduled", "live", "completed", or "cancelled".',
			],
			'_vl_webinar_price'                  => [
				'type'              => 'integer',
				'single'            => true,
				'default'           => 0,
				'show_in_rest'      => false,
				'sanitize_callback' => 'absint',
				'auth_callback'     => $auth,
				'description'       => 'Price in minor units (kopiyky). 0 = free.',
			],
			'_vl_webinar_currency'               => [
				'type'              => 'string',
				'single'            => true,
				'default'           => 'UAH',
				'show_in_rest'      => false,
				'sanitize_callback' => static fn ( mixed $v ): string => self::sanitize_currency_code( $v ),
				'auth_callback'     => $auth,
				'description'       => 'ISO 4217 currency code.',
			],
			'_vl_webinar_max_attendees'          => [
				'type'              => 'integer',
				'single'            => true,
				'default'           => 0,
				'show_in_rest'      => false,
				'sanitize_callback' => 'absint',
				'auth_callback'     => $auth,
				'description'       => 'Maximum registered attendees (0 = unlimited).',
			],
			'_vl_webinar_registration_opens_at'  => [
				'type'              => 'string',
				'single'            => true,
				'default'           => '',
				'show_in_rest'      => false,
				'sanitize_callback' => static fn ( mixed $v ): string => self::sanitize_iso8601( $v ),
				'auth_callback'     => $auth,
				'description'       => 'ISO 8601 UTC timestamp when registration opens.',
			],
			'_vl_webinar_registration_closes_at' => [
				'type'              => 'string',
				'single'            => true,
				'default'           => '',
				'show_in_rest'      => false,
				'sanitize_callback' => static fn ( mixed $v ): string => self::sanitize_iso8601( $v ),
				'auth_callback'     => $auth,
				'description'       => 'ISO 8601 UTC timestamp when registration closes.',
			],
			'_vl_webinar_zoom_meeting_id'        => [
				'type'              => 'string',
				'single'            => true,
				'default'           => '',
				'show_in_rest'      => false,
				'sanitize_callback' => 'sanitize_text_field',
				'auth_callback'     => $auth,
				'description'       => 'Zoom meeting ID.',
			],
			'_vl_webinar_zoom_join_url'          => [
				'type'              => 'string',
				'single'            => true,
				'default'           => '',
				'show_in_rest'      => false,
				'sanitize_callback' => 'esc_url_raw',
				'auth_callback'     => $auth,
				'description'       => 'Zoom participant join URL.',
			],
			'_vl_webinar_zoom_start_url'         => [
				'type'              => 'string',
				'single'            => true,
				'default'           => '',
				'show_in_rest'      => false,
				'sanitize_callback' => 'esc_url_raw',
				'auth_callback'     => $auth,
				'description'       => 'Zoom host start URL.',
			],
			'_vl_webinar_zoom_password'          => [
				'type'              => 'string',
				'single'            => true,
				'default'           => '',
				'show_in_rest'      => false,
				'sanitize_callback' => 'sanitize_text_field',
				'auth_callback'     => $auth,
				'description'       => 'Zoom meeting password.',
			],
			'_vl_webinar_recording_url'          => [
				'type'              => 'string',
				'single'            => true,
				'default'           => '',
				'show_in_rest'      => false,
				'sanitize_callback' => 'esc_url_raw',
				'auth_callback'     => $auth,
				'description'       => 'Recording URL, populated after the event.',
			],
			'_vl_webinar_recording_access_days'  => [
				'type'              => 'integer',
				'single'            => true,
				'default'           => 0,
				'show_in_rest'      => false,
				'sanitize_callback' => 'absint',
				'auth_callback'     => $auth,
				'description'       => 'Days of recording access after the event (0 = no recording offered).',
			],
			'_vl_webinar_preview_video_url'      => [
				'type'              => 'string',
				'single'            => true,
				'default'           => '',
				'show_in_rest'      => false,
				'sanitize_callback' => 'esc_url_raw',
				'auth_callback'     => $auth,
				'description'       => 'Promotional preview video URL for the catalog.',
			],
		];
	}

	/**
	 * Accept ISO 8601 extended date-times (with "Z" or numeric offset),
	 * reject everything else — empty string is a valid "not set" state.
	 */
	private static function sanitize_iso8601( mixed $value ): string {
		if ( ! is_string( $value ) || '' === $value ) {
			return '';
		}
		if ( 1 !== preg_match(
			'/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+\-]\d{2}:?\d{2})$/',
			$value
		) ) {
			return '';
		}
		try {
			new \DateTimeImmutable( $value );
		} catch ( \Throwable ) {
			return '';
		}
		return $value;
	}

	/**
	 * Normalize to uppercase ISO 4217 — three letters, fallback "UAH".
	 */
	private static function sanitize_currency_code( mixed $value ): string {
		if ( ! is_string( $value ) ) {
			return 'UAH';
		}
		$upper = strtoupper( trim( $value ) );
		if ( 1 === preg_match( '/^[A-Z]{3}$/', $upper ) ) {
			return $upper;
		}
		return 'UAH';
	}

	/**
	 * Enum: "scheduled" | "live" | "completed" | "cancelled". Unknown values fall back to "scheduled".
	 */
	private static function sanitize_status( mixed $value ): string {
		if ( is_string( $value ) && in_array( $value, [ 'scheduled', 'live', 'completed', 'cancelled' ], true ) ) {
			return $value;
		}
		return 'scheduled';
	}
}
