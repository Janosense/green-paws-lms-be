<?php

declare(strict_types=1);

namespace VL\LMS\Import\Storage;

/**
 * The token folders an upload waits in between the preview and the
 * confirmation: `uploads/vl-lms-import/<token>/`
 * (`docs/DECISIONS.md` 2026-09-11 — temp folder).
 *
 * The token is the folder name, bound to the uploading user through
 * `meta.json`. `index.html` and `.htaccess` guard the folder on Apache; on
 * nginx the random token and the TTL are the protection until the web-server
 * deny of Sprint 1 Step 9.
 *
 * @author Tymofii Synianskyi
 */
final class TempStore {

	/**
	 * Exactly 32 lowercase hex characters — `D` keeps `$` from accepting a
	 * trailing newline. A token is checked against it before any path is
	 * built from it.
	 */
	public const TOKEN_PATTERN = '/^[0-9a-f]{32}$/D';

	public const FOLDER = 'vl-lms-import';

	private const META_FILE = 'meta.json';

	public function __construct(
		private readonly ImportConfig $config
	) {
	}

	/**
	 * Creates a new token folder owned by `$user_id`.
	 *
	 * @throws TempStoreException When the folder or one of its files cannot be written.
	 */
	public function create( int $user_id ): Handle {
		$token = bin2hex( random_bytes( 16 ) );
		$dir   = $this->base_dir() . '/' . $token;
		$meta  = wp_json_encode(
			[
				'user_id'    => $user_id,
				'created_at' => time(),
			]
		);

		if ( false === $meta
			|| ! wp_mkdir_p( $dir )
			|| ! $this->write( $dir . '/index.html', '' )
			|| ! $this->write( $dir . '/.htaccess', "Deny from all\n" )
			|| ! $this->write( $dir . '/' . self::META_FILE, $meta )
		) {
			FileTree::remove( $dir );
			throw TempStoreException::unwritable();
		}

		return new Handle( $token, $dir );
	}

	/**
	 * Opens the token folder for its owner. Ownership is checked before
	 * expiry, so another user learns nothing about the folder.
	 *
	 * @throws TempStoreException Unknown token (malformed, no folder, unreadable `meta.json`), another owner, or past the TTL.
	 */
	public function open( string $token, int $user_id ): Handle {
		$meta = $this->read_meta( $token );

		if ( null === $meta ) {
			throw TempStoreException::unknown_token();
		}

		if ( $meta['user_id'] !== $user_id ) {
			throw TempStoreException::foreign_token();
		}

		if ( $this->is_expired( $meta['created_at'] ) ) {
			throw TempStoreException::expired_token();
		}

		return new Handle( $token, $this->base_dir() . '/' . $token );
	}

	/**
	 * Removes the token folder. A malformed or unknown token does nothing.
	 */
	public function delete( string $token ): void {
		if ( 1 !== preg_match( self::TOKEN_PATTERN, $token ) ) {
			return;
		}

		FileTree::remove( $this->base_dir() . '/' . $token );
	}

	/**
	 * Removes every token folder older than the TTL. A folder whose
	 * `meta.json` is missing or broken ages by its modification time, so it
	 * is swept too; other names in the base folder are left alone.
	 */
	public function sweep(): void {
		$base    = $this->base_dir();
		$entries = is_dir( $base ) ? scandir( $base ) : false;

		foreach ( false === $entries ? [] : $entries as $entry ) {
			$dir = $base . '/' . $entry;

			if ( 1 !== preg_match( self::TOKEN_PATTERN, $entry ) || is_link( $dir ) || ! is_dir( $dir ) ) {
				continue;
			}

			$meta       = $this->read_meta( $entry );
			$created_at = null === $meta ? filemtime( $dir ) : $meta['created_at'];

			if ( false === $created_at || $this->is_expired( $created_at ) ) {
				FileTree::remove( $dir );
			}
		}
	}

	/**
	 * @return array{user_id: int, created_at: int}|null
	 */
	private function read_meta( string $token ): ?array {
		if ( 1 !== preg_match( self::TOKEN_PATTERN, $token ) ) {
			return null;
		}

		$path = $this->base_dir() . '/' . $token . '/' . self::META_FILE;
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return null;
		}

		// A local file inside the token folder, never a URL.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$contents = file_get_contents( $path );
		$meta     = false === $contents ? null : json_decode( $contents, true );

		if ( ! is_array( $meta ) || ! is_int( $meta['user_id'] ?? null ) || ! is_int( $meta['created_at'] ?? null ) ) {
			return null;
		}

		return [
			'user_id'    => $meta['user_id'],
			'created_at' => $meta['created_at'],
		];
	}

	private function is_expired( int $created_at ): bool {
		return time() - $created_at > $this->config->temp_ttl;
	}

	private function write( string $path, string $contents ): bool {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A new file in a folder the importer created; WP_Filesystem is set up only around unzip_file().
		return false !== file_put_contents( $path, $contents );
	}

	/**
	 * Read on every call, so the uploads location is always the current one.
	 * `false` keeps WordPress from creating this month's folder.
	 */
	private function base_dir(): string {
		$uploads = wp_upload_dir( null, false );

		return rtrim( $uploads['basedir'], '/' ) . '/' . self::FOLDER;
	}
}
