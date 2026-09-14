<?php

declare(strict_types=1);

namespace VL\LMS\Import\Storage;

/**
 * The importer's business values — upload limit, temp-folder TTL, image
 * extensions, default pass percent. They are configuration, read from the
 * `vl_lms/import/*` filters, never literals in handlers
 * (`docs/features/course-import/FEATURE.md` → Invariants).
 *
 * @author Tymofii Synianskyi
 */
final readonly class ImportConfig {

	public const DEFAULT_TEMP_TTL = 3600;

	public const DEFAULT_IMAGE_EXTENSIONS = [ 'png', 'jpg', 'jpeg', 'gif', 'webp' ];

	public const DEFAULT_PASS_PERCENT = 70;

	public const DEFAULT_TIME_LIMIT = 300;

	public const DEFAULT_REPORT_TTL = 600;

	private const MAX_PASS_PERCENT = 100;

	/**
	 * @param int          $max_upload_bytes         The largest upload accepted, and the largest total an archive may unpack to.
	 * @param int          $temp_ttl                 Seconds an upload's temp folder lives.
	 * @param list<string> $allowed_image_extensions Lowercase, without the dot. `assets/` files with any other extension are not kept.
	 * @param int          $default_pass_percent     The pass percent when the course file sets no `quiz_pass_percent`.
	 * @param int          $time_limit               Seconds one import run may take; `0` leaves the host's own limit alone.
	 * @param int          $report_ttl               Seconds the one-shot import report survives after a successful import.
	 */
	public function __construct(
		public int $max_upload_bytes,
		public int $temp_ttl,
		public array $allowed_image_extensions,
		public int $default_pass_percent,
		public int $time_limit,
		public int $report_ttl
	) {
	}

	/**
	 * Reads the defaults through `vl_lms/import/config`, then lets the
	 * per-value filters override the upload limit, the TTL and the image
	 * extensions.
	 */
	public static function from_filters(): self {
		$defaults = [
			'max_upload_bytes'         => wp_max_upload_size(),
			'temp_ttl'                 => self::DEFAULT_TEMP_TTL,
			'allowed_image_extensions' => self::DEFAULT_IMAGE_EXTENSIONS,
			'default_pass_percent'     => self::DEFAULT_PASS_PERCENT,
			'time_limit'               => self::DEFAULT_TIME_LIMIT,
			'report_ttl'               => self::DEFAULT_REPORT_TTL,
		];

		/**
		 * Filters the course importer's configuration. A key the returned
		 * array lacks keeps its default.
		 *
		 * @param array<string, mixed> $config `max_upload_bytes`, `temp_ttl`, `allowed_image_extensions`, `default_pass_percent`, `time_limit`, `report_ttl`.
		 */
		$filtered = apply_filters( 'vl_lms/import/config', $defaults ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- The course-import filter names are fixed in FEATURE.md → Interfaces.
		$config   = is_array( $filtered ) ? array_merge( $defaults, $filtered ) : $defaults;

		/**
		 * Filters the largest course upload, in bytes. Also caps the total an
		 * archive may unpack to.
		 *
		 * @param mixed $bytes Default `wp_max_upload_size()`.
		 */
		$max_upload_bytes = apply_filters( 'vl_lms/import/max_upload_bytes', $config['max_upload_bytes'] ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- The course-import filter names are fixed in FEATURE.md → Interfaces.

		/**
		 * Filters how many seconds an upload's temp folder lives.
		 *
		 * @param mixed $seconds Default one hour.
		 */
		$temp_ttl = apply_filters( 'vl_lms/import/temp_ttl', $config['temp_ttl'] ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- The course-import filter names are fixed in FEATURE.md → Interfaces.

		/**
		 * Filters the image extensions kept from an archive's `assets/`.
		 *
		 * @param mixed $extensions Default png, jpg, jpeg, gif, webp.
		 */
		$extensions = apply_filters( 'vl_lms/import/allowed_image_extensions', $config['allowed_image_extensions'] ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- The course-import filter names are fixed in FEATURE.md → Interfaces.

		/**
		 * Filters how many seconds one import run may take. `0` asks the
		 * importer not to touch the host's own time limit at all.
		 *
		 * @param mixed $seconds Default five minutes.
		 */
		$time_limit = apply_filters( 'vl_lms/import/time_limit', $config['time_limit'] ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- The course-import filter names are fixed in FEATURE.md → Interfaces.

		/**
		 * Filters how many seconds the one-shot import report stays readable.
		 *
		 * @param mixed $seconds Default ten minutes.
		 */
		$report_ttl = apply_filters( 'vl_lms/import/report_ttl', $config['report_ttl'] ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- The course-import filter names are fixed in FEATURE.md → Interfaces.

		return new self(
			max( 1, self::to_int( $max_upload_bytes ) ),
			max( 1, self::to_int( $temp_ttl ) ),
			self::extensions( $extensions ),
			min( self::MAX_PASS_PERCENT, max( 0, self::to_int( $config['default_pass_percent'] ) ) ),
			max( 0, self::to_int( $time_limit ) ),
			max( 1, self::to_int( $report_ttl ) )
		);
	}

	private static function to_int( mixed $value ): int {
		return is_numeric( $value ) ? (int) $value : 0;
	}

	/**
	 * @return list<string>
	 */
	private static function extensions( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}

		$extensions = [];
		foreach ( $value as $extension ) {
			if ( ! is_string( $extension ) ) {
				continue;
			}

			$extension = strtolower( ltrim( trim( $extension ), '.' ) );
			if ( '' !== $extension && ! in_array( $extension, $extensions, true ) ) {
				$extensions[] = $extension;
			}
		}

		return $extensions;
	}
}
