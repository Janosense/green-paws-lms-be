<?php

declare(strict_types=1);

namespace VL\LMS\Import\Storage;

use Throwable;
use ZipArchive;

/**
 * Turns one uploaded `.md` or `.zip` into a token folder holding `course.md`
 * and the allowed images under `assets/` — nothing else from the upload
 * survives (`docs/features/course-import/FEATURE.md` → Invariants).
 *
 * An archive is read in two passes. Its listing decides first what is kept
 * and whether it unpacks to more than the upload limit; only then
 * `unzip_file()` extracts it into a scratch folder, and the kept entries
 * are copied out of it.
 *
 * @author Tymofii Synianskyi
 */
final class UploadIntake {

	/**
	 * The upload types, for `wp_check_filetype_and_ext()` and
	 * `wp_handle_upload()`. WordPress has no mime type for `.md`, and it
	 * accepts content `finfo` reads as `text/plain` only under a `text/plain`
	 * extension.
	 */
	public const MIMES = [
		'md'  => 'text/plain',
		'zip' => 'application/zip',
	];

	public const COURSE_FILE = 'course.md';

	private const STORED_ZIP = 'upload.zip';

	private const SCRATCH = '.unzip';

	private const ASSETS = 'assets/';

	private const S_IFMT = 0o170000;

	private const S_IFLNK = 0o120000;

	public function __construct(
		private readonly ImportConfig $config,
		private readonly TempStore $store
	) {
	}

	/**
	 * Checks the upload, then stores it in a new token folder owned by
	 * `$user_id`: a `.md` as `course.md`, a `.zip` through {@see extract()}.
	 * A failure after the folder exists deletes it.
	 *
	 * @param array{name: string, type: string, tmp_name: string, error: int, size: int} $file One `$_FILES` entry.
	 *
	 * @throws IntakeException    When the upload is refused or cannot be unpacked.
	 * @throws TempStoreException When the token folder cannot be written.
	 */
	public function accept( array $file, int $user_id ): IntakeResult {
		$extension = $this->check_upload( $file );
		$handle    = $this->store->create( $user_id );

		try {
			$stored = $this->store_upload( $file, $handle, $extension );

			if ( 'md' === $extension ) {
				return new IntakeResult( $handle, $stored, [] );
			}

			$result = $this->extract( $handle, $stored );
			wp_delete_file( $stored );

			return $result;
		} catch ( Throwable $exception ) {
			$this->store->delete( $handle->token );

			throw $exception;
		}
	}

	/**
	 * Unpacks an archive into the token folder: the root's `course.md` and the
	 * allowed images under the root's `assets/`. Never deletes the archive it
	 * was given. Public on its own because `wp_handle_upload()` accepts only a
	 * file that arrived by HTTP POST, so a manual check from WP-CLI starts here.
	 *
	 * @throws IntakeException When the archive is refused or cannot be unpacked.
	 */
	public function extract( Handle $handle, string $zip_path ): IntakeResult {
		if ( ! class_exists( ZipArchive::class, false ) ) {
			throw IntakeException::archive_unsupported();
		}

		$kept    = $this->kept_entries( $zip_path );
		$scratch = $handle->dir . '/' . self::SCRATCH;
		$assets  = [];

		$this->load_file_api();

		try {
			if ( true !== WP_Filesystem() ) {
				throw IntakeException::extract_failed();
			}

			$unzipped = unzip_file( $zip_path, $scratch );
			if ( is_wp_error( $unzipped ) ) {
				throw IntakeException::extract_failed( $unzipped->get_error_message() );
			}

			foreach ( $kept as $name => $target ) {
				$this->copy_entry( $scratch . '/' . $name, $handle->dir . '/' . $target );

				if ( self::COURSE_FILE !== $target ) {
					$assets[] = $target;
				}
			}
		} finally {
			FileTree::remove( $scratch );
		}

		sort( $assets );

		return new IntakeResult( $handle, $handle->dir . '/' . self::COURSE_FILE, $assets );
	}

	/**
	 * @param array{name: string, type: string, tmp_name: string, error: int, size: int} $file
	 *
	 * @return string The lowercase extension, a key of {@see self::MIMES}.
	 */
	private function check_upload( array $file ): string {
		if ( UPLOAD_ERR_INI_SIZE === $file['error'] || UPLOAD_ERR_FORM_SIZE === $file['error'] ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- An int limit; the wp-admin handlers escape the message when they show it.
			throw IntakeException::too_large( $this->config->max_upload_bytes );
		}

		if ( UPLOAD_ERR_OK !== $file['error'] ) {
			throw IntakeException::upload_failed();
		}

		if ( $file['size'] > $this->config->max_upload_bytes ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- An int limit; the wp-admin handlers escape the message when they show it.
			throw IntakeException::too_large( $this->config->max_upload_bytes );
		}

		$extension = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
		if ( ! isset( self::MIMES[ $extension ] ) ) {
			throw IntakeException::wrong_type();
		}

		$type = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], self::MIMES );
		if ( empty( $type['ext'] ) || empty( $type['type'] ) ) {
			throw IntakeException::wrong_type();
		}

		return $extension;
	}

	/**
	 * Moves the upload into the token folder under a fixed name.
	 *
	 * @param array{name: string, type: string, tmp_name: string, error: int, size: int} $file
	 *
	 * @return string The stored file.
	 */
	private function store_upload( array $file, Handle $handle, string $extension ): string {
		$this->load_file_api();

		$stored_name = 'md' === $extension ? self::COURSE_FILE : self::STORED_ZIP;
		$upload_dir  = static function ( array $uploads ) use ( $handle ): array {
			$uploads['path']   = $handle->dir;
			$uploads['url']    = $uploads['baseurl'] . '/' . TempStore::FOLDER . '/' . $handle->token;
			$uploads['subdir'] = '/' . TempStore::FOLDER . '/' . $handle->token;

			return $uploads;
		};

		add_filter( 'upload_dir', $upload_dir );

		try {
			$uploaded = wp_handle_upload(
				$file,
				[
					'test_form'                => false,
					'mimes'                    => self::MIMES,
					'unique_filename_callback' => static fn (): string => $stored_name,
				]
			);
		} finally {
			remove_filter( 'upload_dir', $upload_dir );
		}

		if ( isset( $uploaded['error'] ) || ! isset( $uploaded['file'] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- WordPress's own error text; the wp-admin handlers escape the message when they show it.
			throw IntakeException::upload_failed( (string) ( $uploaded['error'] ?? '' ) );
		}

		return $uploaded['file'];
	}

	/**
	 * Reads the archive's listing. Refuses an archive that unpacks to more
	 * than the upload limit, finds the root that holds `course.md`, and keeps
	 * that file plus the allowed images under the root's `assets/`.
	 *
	 * @return array<string, string> Archive entry name => path inside the token folder.
	 */
	private function kept_entries( string $zip_path ): array {
		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path, ZipArchive::CHECKCONS ) ) {
			throw IntakeException::archive_unreadable();
		}

		$files = [];
		$total = 0;
		$count = $zip->count();

		try {
			for ( $index = 0; $index < $count; $index++ ) {
				$stat = $zip->statIndex( $index );
				if ( false === $stat ) {
					throw IntakeException::archive_unreadable();
				}

				$total += $stat['size'];
				if ( $total > $this->config->max_upload_bytes ) {
					throw IntakeException::archive_too_large( $this->config->max_upload_bytes );
				}

				if ( $this->is_safe_file( $zip, $index, $stat['name'] ) ) {
					$files[] = $stat['name'];
				}
			}
		} finally {
			$zip->close();
		}

		$root = $this->root( $files );
		$kept = [ $root . self::COURSE_FILE => self::COURSE_FILE ];

		foreach ( $files as $name ) {
			if ( ! str_starts_with( $name, $root . self::ASSETS ) ) {
				continue;
			}

			$relative = substr( $name, strlen( $root ) );
			if ( in_array( strtolower( pathinfo( $relative, PATHINFO_EXTENSION ) ), $this->config->allowed_image_extensions, true ) ) {
				$kept[ $name ] = $relative;
			}
		}

		return $kept;
	}

	/**
	 * A regular file whose name cannot leave the extraction folder. Folder
	 * entries, macOS metadata, `..` segments, absolute and drive paths,
	 * backslashes and symlinks are never kept.
	 */
	private function is_safe_file( ZipArchive $zip, int $index, string $name ): bool {
		if ( '' === $name
			|| str_ends_with( $name, '/' )
			|| str_starts_with( $name, '__MACOSX/' )
			|| str_starts_with( $name, '/' )
			|| str_contains( $name, '\\' )
			|| 1 === preg_match( '/^[A-Za-z]:/', $name )
			|| in_array( '..', explode( '/', $name ), true )
		) {
			return false;
		}

		$opsys      = 0;
		$attributes = 0;
		if ( $zip->getExternalAttributesIndex( $index, $opsys, $attributes ) && ZipArchive::OPSYS_UNIX === $opsys ) {
			return self::S_IFLNK !== ( ( $attributes >> 16 ) & self::S_IFMT );
		}

		return true;
	}

	/**
	 * The folder prefix `course.md` sits under: `''` for the archive's top
	 * level, `folder/` when the top level has no `course.md` and exactly one
	 * folder, which holds it.
	 *
	 * @param list<string> $files Safe file names from the listing.
	 */
	private function root( array $files ): string {
		if ( in_array( self::COURSE_FILE, $files, true ) ) {
			return '';
		}

		$folders = [];
		foreach ( $files as $name ) {
			$slash = strpos( $name, '/' );
			if ( false !== $slash ) {
				$folders[ substr( $name, 0, $slash + 1 ) ] = true;
			}
		}

		$folder = 1 === count( $folders ) ? (string) array_key_first( $folders ) : null;
		if ( null !== $folder && in_array( $folder . self::COURSE_FILE, $files, true ) ) {
			return $folder;
		}

		throw IntakeException::no_course_file();
	}

	private function copy_entry( string $source, string $target ): void {
		if ( is_link( $source ) || ! is_file( $source ) || ! wp_mkdir_p( dirname( $target ) ) || ! copy( $source, $target ) ) {
			throw IntakeException::extract_failed();
		}
	}

	/**
	 * `wp_handle_upload()`, `WP_Filesystem()` and `unzip_file()` live in an
	 * admin include: `admin-post.php` loads it, other entry points may not.
	 */
	private function load_file_api(): void {
		if ( ! function_exists( 'wp_handle_upload' ) || ! function_exists( 'unzip_file' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
	}
}
