<?php

declare(strict_types=1);

namespace VL\LMS\CPT;

/**
 * Registers the `vl_course` custom post type and its meta schema.
 *
 * A course is the central domain entity in VL LMS. One CPT covers both
 * self-paced and cohort variants — the `_vl_course_type` meta distinguishes
 * them, and cohort-specific scheduling lives in separate date meta fields
 * that stay empty for self-paced courses.
 *
 * The CPT itself is not exposed through `wp/v2` — the Nuxt frontend
 * consumes courses exclusively through the `vl/v1/*` controllers. Meta
 * keys are still registered so WP runs sanitize callbacks on every write
 * and so we have a single, reviewable source of truth for the schema.
 *
 * @author Tymofii Synianskyi
 */
final class CourseType extends AbstractCptRegistrar {

	protected function post_type(): string {
		return 'vl_course';
	}

	protected function singular_label(): string {
		return 'Course';
	}

	protected function plural_label(): string {
		return 'Courses';
	}

	protected function capability_type(): array {
		return [ 'vl_course', 'vl_courses' ];
	}

	protected function supports(): array {
		return [ 'title', 'editor', 'excerpt', 'thumbnail', 'author', 'custom-fields' ];
	}

	protected function menu_icon(): ?string {
		return 'dashicons-welcome-learn-more';
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
			'_vl_course_type'                 => [
				'type'              => 'string',
				'single'            => true,
				'default'           => 'self_paced',
				'show_in_rest'      => false,
				'sanitize_callback' => static fn ( mixed $v ): string => self::sanitize_course_type( $v ),
				'auth_callback'     => $auth,
				'description'       => 'Course variant — "self_paced" or "cohort". Drives child content structure.',
			],
			'_vl_course_price'                => [
				'type'              => 'number',
				'single'            => true,
				'default'           => 0,
				'show_in_rest'      => false,
				'sanitize_callback' => static fn ( mixed $v ): float => self::sanitize_price_uah( $v ),
				'auth_callback'     => $auth,
				'description'       => 'Price in UAH (decimal, 2 places).',
			],
			'_vl_course_cover_image_id'       => [
				'type'              => 'integer',
				'single'            => true,
				'default'           => 0,
				'show_in_rest'      => false,
				'sanitize_callback' => 'absint',
				'auth_callback'     => $auth,
				'description'       => 'WP attachment ID for the course cover image. 0 = no cover.',
			],
			'_vl_course_currency'             => [
				'type'              => 'string',
				'single'            => true,
				'default'           => 'UAH',
				'show_in_rest'      => false,
				'sanitize_callback' => static fn ( mixed $v ): string => self::sanitize_currency_code( $v ),
				'auth_callback'     => $auth,
				'description'       => 'ISO 4217 currency code.',
			],
			'_vl_course_duration_hours'       => [
				'type'              => 'number',
				'single'            => true,
				'default'           => 0.0,
				'show_in_rest'      => false,
				'sanitize_callback' => static fn ( mixed $v ): float => self::sanitize_positive_float( $v ),
				'auth_callback'     => $auth,
				'description'       => 'Estimated total duration in hours.',
			],
			'_vl_course_enrollment_open'      => [
				'type'              => 'boolean',
				'single'            => true,
				'default'           => true,
				'show_in_rest'      => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
				'auth_callback'     => $auth,
				'description'       => 'Whether enrollment is currently open.',
			],
			'_vl_course_enrollment_opens_at'  => [
				'type'              => 'string',
				'single'            => true,
				'default'           => '',
				'show_in_rest'      => false,
				'sanitize_callback' => static fn ( mixed $v ): string => self::sanitize_iso8601( $v ),
				'auth_callback'     => $auth,
				'description'       => 'ISO 8601 timestamp when enrollment opens (cohort).',
			],
			'_vl_course_enrollment_closes_at' => [
				'type'              => 'string',
				'single'            => true,
				'default'           => '',
				'show_in_rest'      => false,
				'sanitize_callback' => static fn ( mixed $v ): string => self::sanitize_iso8601( $v ),
				'auth_callback'     => $auth,
				'description'       => 'ISO 8601 timestamp when enrollment closes (cohort).',
			],
			'_vl_course_starts_at'            => [
				'type'              => 'string',
				'single'            => true,
				'default'           => '',
				'show_in_rest'      => false,
				'sanitize_callback' => static fn ( mixed $v ): string => self::sanitize_iso8601( $v ),
				'auth_callback'     => $auth,
				'description'       => 'ISO 8601 timestamp when the cohort course starts.',
			],
			'_vl_course_ends_at'              => [
				'type'              => 'string',
				'single'            => true,
				'default'           => '',
				'show_in_rest'      => false,
				'sanitize_callback' => static fn ( mixed $v ): string => self::sanitize_iso8601( $v ),
				'auth_callback'     => $auth,
				'description'       => 'ISO 8601 timestamp when the cohort course ends.',
			],
			'_vl_course_max_students'         => [
				'type'              => 'integer',
				'single'            => true,
				'default'           => 0,
				'show_in_rest'      => false,
				'sanitize_callback' => 'absint',
				'auth_callback'     => $auth,
				'description'       => 'Maximum enrolled students (0 = unlimited).',
			],
			'_vl_course_preview_video_url'    => [
				'type'              => 'string',
				'single'            => true,
				'default'           => '',
				'show_in_rest'      => false,
				'sanitize_callback' => 'esc_url_raw',
				'auth_callback'     => $auth,
				'description'       => 'Optional promotional preview video URL.',
			],
			'_vl_course_certificate_enabled'  => [
				'type'              => 'boolean',
				'single'            => true,
				'default'           => false,
				'show_in_rest'      => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
				'auth_callback'     => $auth,
				'description'       => 'Whether a certificate is issued on completion.',
			],
			'_vl_course_passing_threshold'    => [
				'type'              => 'integer',
				'single'            => true,
				'default'           => 80,
				'show_in_rest'      => false,
				'sanitize_callback' => static fn ( mixed $v ): int => self::sanitize_percent( $v ),
				'auth_callback'     => $auth,
				'description'       => 'Completion threshold in percent (0–100).',
			],
		];
	}

	/**
	 * Sanitize a price stored in UAH.
	 * Coerces to float, clamps to >= 0, rounds to 2 decimal places.
	 */
	protected static function sanitize_price_uah( mixed $value ): float {
		$price = (float) $value;
		if ( $price < 0.0 ) {
			$price = 0.0;
		}
		return round( $price, 2 );
	}

	/**
	 * Enum: "self_paced" | "cohort". Unknown values fall back to "self_paced".
	 */
	private static function sanitize_course_type( mixed $value ): string {
		if ( is_string( $value ) && in_array( $value, [ 'self_paced', 'cohort' ], true ) ) {
			return $value;
		}
		return 'self_paced';
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
	 * Non-negative float. Non-numeric input collapses to 0.0.
	 */
	private static function sanitize_positive_float( mixed $value ): float {
		if ( ! is_numeric( $value ) ) {
			return 0.0;
		}
		$float = (float) $value;
		return $float < 0.0 ? 0.0 : $float;
	}

	/**
	 * Clamp to [0, 100]. Non-numeric input collapses to 0.
	 */
	private static function sanitize_percent( mixed $value ): int {
		if ( ! is_numeric( $value ) ) {
			return 0;
		}
		$int = (int) $value;
		if ( $int < 0 ) {
			return 0;
		}
		if ( $int > 100 ) {
			return 100;
		}
		return $int;
	}
}
