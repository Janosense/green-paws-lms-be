<?php

declare(strict_types=1);

namespace VL\LMS\CPT;

/**
 * Registers the `vl_session` custom post type and its meta schema.
 *
 * A session is a scheduled online class inside a cohort-type course. It
 * attaches to a `vl_course` via `post_parent` and carries Zoom meeting
 * details plus a lifecycle status. Unlike `vl_lesson`, a session is
 * time-bound — its entire meta shape (scheduled windows, join URLs, live
 * status, recording URL) is distinct enough to warrant a dedicated CPT
 * rather than polluting the lesson schema with half-NULL rows.
 *
 * The CPT permits any parent at the schema level; the "cohort courses
 * only" rule is enforced in services and REST controllers, not here.
 *
 * Phase 1 fills Zoom meta manually through wp-admin. Phase 1.5 introduces
 * a synchronizer service that writes the same keys via the Zoom API on
 * `save_post_vl_session` — the schema does not change between phases.
 *
 * Hidden from `wp/v2` — the Nuxt frontend reads sessions through the
 * `vl/v1/*` controllers.
 *
 * @author Tymofii Synianskyi
 */
final class SessionType extends AbstractCptRegistrar {

	protected function post_type(): string {
		return 'vl_session';
	}

	protected function singular_label(): string {
		return 'Session';
	}

	protected function plural_label(): string {
		return 'Sessions';
	}

	protected function capability_type(): array {
		return [ 'vl_session', 'vl_sessions' ];
	}

	protected function supports(): array {
		return [ 'title', 'editor', 'custom-fields', 'page-attributes' ];
	}

	protected function menu_icon(): string {
		return 'dashicons-calendar-alt';
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
			'_vl_session_number'                    => [
				'type'              => 'integer',
				'single'            => true,
				'default'           => 0,
				'show_in_rest'      => false,
				'sanitize_callback' => 'absint',
				'auth_callback'     => $auth,
				'description'       => 'Sequence number inside the course (1, 2, 3…).',
			],
			'_vl_session_scheduled_start'           => [
				'type'              => 'string',
				'single'            => true,
				'default'           => '',
				'show_in_rest'      => false,
				'sanitize_callback' => static fn ( mixed $v ): string => self::sanitize_iso8601( $v ),
				'auth_callback'     => $auth,
				'description'       => 'ISO 8601 UTC timestamp when the session starts.',
			],
			'_vl_session_scheduled_end'             => [
				'type'              => 'string',
				'single'            => true,
				'default'           => '',
				'show_in_rest'      => false,
				'sanitize_callback' => static fn ( mixed $v ): string => self::sanitize_iso8601( $v ),
				'auth_callback'     => $auth,
				'description'       => 'ISO 8601 UTC timestamp when the session ends.',
			],
			'_vl_session_status'                    => [
				'type'              => 'string',
				'single'            => true,
				'default'           => 'scheduled',
				'show_in_rest'      => false,
				'sanitize_callback' => static fn ( mixed $v ): string => self::sanitize_status( $v ),
				'auth_callback'     => $auth,
				'description'       => 'Lifecycle state — "scheduled", "live", "completed", or "cancelled".',
			],
			'_vl_session_zoom_meeting_id'           => [
				'type'              => 'string',
				'single'            => true,
				'default'           => '',
				'show_in_rest'      => false,
				'sanitize_callback' => 'sanitize_text_field',
				'auth_callback'     => $auth,
				'description'       => 'Zoom meeting ID.',
			],
			'_vl_session_zoom_join_url'             => [
				'type'              => 'string',
				'single'            => true,
				'default'           => '',
				'show_in_rest'      => false,
				'sanitize_callback' => 'esc_url_raw',
				'auth_callback'     => $auth,
				'description'       => 'Zoom participant join URL.',
			],
			'_vl_session_zoom_start_url'            => [
				'type'              => 'string',
				'single'            => true,
				'default'           => '',
				'show_in_rest'      => false,
				'sanitize_callback' => 'esc_url_raw',
				'auth_callback'     => $auth,
				'description'       => 'Zoom host start URL.',
			],
			'_vl_session_zoom_password'             => [
				'type'              => 'string',
				'single'            => true,
				'default'           => '',
				'show_in_rest'      => false,
				'sanitize_callback' => 'sanitize_text_field',
				'auth_callback'     => $auth,
				'description'       => 'Zoom meeting password.',
			],
			'_vl_session_recording_url'             => [
				'type'              => 'string',
				'single'            => true,
				'default'           => '',
				'show_in_rest'      => false,
				'sanitize_callback' => 'esc_url_raw',
				'auth_callback'     => $auth,
				'description'       => 'Recording URL, populated after the session.',
			],
			'_vl_session_recording_available_until' => [
				'type'              => 'string',
				'single'            => true,
				'default'           => '',
				'show_in_rest'      => false,
				'sanitize_callback' => static fn ( mixed $v ): string => self::sanitize_iso8601( $v ),
				'auth_callback'     => $auth,
				'description'       => 'ISO 8601 UTC end of the recording access window.',
			],
			'_vl_session_materials'                 => [
				'type'              => 'array',
				'single'            => true,
				'default'           => [],
				'show_in_rest'      => false,
				'sanitize_callback' => static fn ( mixed $v ): array => self::sanitize_materials( $v ),
				'auth_callback'     => $auth,
				'description'       => 'Downloadable files — list of { url, name, size } objects.',
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
	 * Enum: "scheduled" | "live" | "completed" | "cancelled". Unknown values fall back to "scheduled".
	 */
	private static function sanitize_status( mixed $value ): string {
		if ( is_string( $value ) && in_array( $value, [ 'scheduled', 'live', 'completed', 'cancelled' ], true ) ) {
			return $value;
		}
		return 'scheduled';
	}

	/**
	 * Normalize a materials list.
	 *
	 * Each element is coerced to `{ url, name, size }`. Elements whose URL
	 * is empty after sanitization are dropped; extra keys are stripped.
	 * Non-array input collapses to an empty list.
	 *
	 * @return list<array{url: string, name: string, size: int}>
	 */
	private static function sanitize_materials( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}

		$out = [];
		foreach ( $value as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$url = isset( $item['url'] ) && is_string( $item['url'] )
				? esc_url_raw( $item['url'] )
				: '';
			if ( '' === $url ) {
				continue;
			}

			$name = isset( $item['name'] ) && is_string( $item['name'] )
				? sanitize_text_field( $item['name'] )
				: '';
			$size = isset( $item['size'] ) ? absint( $item['size'] ) : 0;

			$out[] = [
				'url'  => $url,
				'name' => $name,
				'size' => $size,
			];
		}

		return $out;
	}
}
