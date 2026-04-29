<?php

declare(strict_types=1);

namespace VL\LMS\Cli\Seeders;

use VL\LMS\Cli\SeederContext;
use VL\LMS\Cli\SeederResult;

/**
 * Sideloads cover and avatar attachments for the demo data set.
 *
 * Primary source: Picsum (`https://picsum.photos/seed/{key}/{w}/{h}`).
 * Fallback: locally bundled JPEGs under `assets/demo/`. Re-runs reuse the
 * already-imported attachment by looking up the marker meta + a stable
 * `_vl_demo_seed_key` tag, so the attachment ID remains the same across
 * runs — important for `_vl_course_cover_image_id` to keep referring to a
 * valid attachment without re-uploading the file.
 *
 * Each lookup is keyed by a stable string (e.g. `gp-course-3`,
 * `gp-instructor-1`); seeders pass the same key to {@see self::ensure()}
 * each run.
 *
 * @author Tymofii Synianskyi
 */
final class MediaSeeder {

	public const string DEMO_META_KEY = '_vl_demo_seed';
	public const string KEY_META_KEY  = '_vl_demo_seed_key';

	public const string COVER_PREFIX_COURSE  = 'gp-course-';
	public const string COVER_PREFIX_WEBINAR = 'gp-webinar-';
	public const string AVATAR_PREFIX        = 'gp-instructor-';
	public const string INLINE_IMAGE_KEY     = 'gp-inline-1';

	private const int COVER_WIDTH     = 1920;
	private const int COVER_HEIGHT    = 720;
	private const int AVATAR_SIZE     = 400;
	private const int INLINE_WIDTH    = 1200;
	private const int INLINE_HEIGHT   = 800;
	private const int REQUEST_TIMEOUT = 10;

	public function __construct( private readonly string $assets_dir ) {
	}

	/**
	 * Idempotently sideloads (or returns) a cover image. Returns 0 on any
	 * failure — the caller logs a warning and proceeds without a cover.
	 */
	public function ensure_cover( SeederContext $context, string $stable_key ): int {
		return $this->ensure(
			$context,
			$stable_key,
			self::COVER_WIDTH,
			self::COVER_HEIGHT,
			$this->local_filename_for( $stable_key, 'cover' )
		);
	}

	public function ensure_avatar( SeederContext $context, string $stable_key ): int {
		return $this->ensure(
			$context,
			$stable_key,
			self::AVATAR_SIZE,
			self::AVATAR_SIZE,
			$this->local_filename_for( $stable_key, 'avatar' )
		);
	}

	public function ensure_inline( SeederContext $context ): int {
		return $this->ensure(
			$context,
			self::INLINE_IMAGE_KEY,
			self::INLINE_WIDTH,
			self::INLINE_HEIGHT,
			null
		);
	}

	private function ensure(
		SeederContext $context,
		string $stable_key,
		int $width,
		int $height,
		?string $local_fallback
	): int {
		$existing = $this->find_by_key( $stable_key );
		if ( null !== $existing ) {
			return $existing;
		}

		$tmp_file = $this->download_picsum( $stable_key, $width, $height );

		if ( null === $tmp_file && null !== $local_fallback ) {
			$tmp_file = $this->copy_local_fallback( $local_fallback );
		}

		if ( null === $tmp_file ) {
			$context->log(
				sprintf(
					/* translators: %s: stable key. */
					__( 'WARNING: could not source image for "%s"; continuing without it.', 'vl-lms' ),
					$stable_key
				)
			);
			return 0;
		}

		$attachment_id = $this->sideload_to_media_library( $tmp_file, $stable_key );

		if ( file_exists( $tmp_file ) ) {
			wp_delete_file( $tmp_file );
		}

		if ( 0 === $attachment_id ) {
			$context->log(
				sprintf(
					/* translators: %s: stable key. */
					__( 'WARNING: could not import image for "%s" into the media library.', 'vl-lms' ),
					$stable_key
				)
			);
			return 0;
		}

		update_post_meta( $attachment_id, self::DEMO_META_KEY, '1' );
		update_post_meta( $attachment_id, self::KEY_META_KEY, $stable_key );

		return $attachment_id;
	}

	private function find_by_key( string $stable_key ): ?int {
		$query = new \WP_Query(
			[
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'posts_per_page'         => 1,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'fields'                 => 'ids',
				'meta_query'             => [
					'relation' => 'AND',
					[
						'key'   => self::DEMO_META_KEY,
						'value' => '1',
					],
					[
						'key'   => self::KEY_META_KEY,
						'value' => $stable_key,
					],
				],
			]
		);

		$ids = $query->posts;
		if ( ! is_array( $ids ) || [] === $ids ) {
			return null;
		}
		$id = (int) $ids[0];
		return $id > 0 ? $id : null;
	}

	private function download_picsum( string $stable_key, int $width, int $height ): ?string {
		$url = sprintf( 'https://picsum.photos/seed/%s/%d/%d', rawurlencode( $stable_key ), $width, $height );

		$response = wp_remote_get(
			$url,
			[
				'timeout'     => self::REQUEST_TIMEOUT,
				'redirection' => 5,
			]
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			return null;
		}

		$body = wp_remote_retrieve_body( $response );
		if ( '' === $body ) {
			return null;
		}

		$tmp = wp_tempnam( $stable_key . '.jpg' );
		if ( '' === $tmp ) {
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$bytes = file_put_contents( $tmp, $body );
		if ( false === $bytes || 0 === $bytes ) {
			wp_delete_file( $tmp );
			return null;
		}

		return $tmp;
	}

	private function copy_local_fallback( string $local_filename ): ?string {
		$path = trailingslashit( $this->assets_dir ) . $local_filename;
		if ( ! file_exists( $path ) ) {
			return null;
		}

		$tmp = wp_tempnam( $local_filename );
		if ( '' === $tmp ) {
			return null;
		}

		if ( ! copy( $path, $tmp ) ) {
			wp_delete_file( $tmp );
			return null;
		}

		return $tmp;
	}

	private function sideload_to_media_library( string $tmp_file, string $stable_key ): int {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$file_array = [
			'name'     => $stable_key . '.jpg',
			'tmp_name' => $tmp_file,
		];

		$attachment_id = media_handle_sideload( $file_array, 0, $stable_key );
		if ( is_wp_error( $attachment_id ) ) {
			return 0;
		}

		return (int) $attachment_id;
	}

	private function local_filename_for( string $stable_key, string $kind ): string {
		// Map "gp-course-3" → "cover-3.jpg", "gp-instructor-2" → "avatar-2.jpg".
		$suffix = preg_replace( '/^[a-z\-]+/', '', $stable_key );
		$suffix = is_string( $suffix ) ? $suffix : '1';
		return $kind . '-' . $suffix . '.jpg';
	}
}
