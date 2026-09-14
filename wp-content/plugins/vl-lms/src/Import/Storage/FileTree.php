<?php

declare(strict_types=1);

namespace VL\LMS\Import\Storage;

/**
 * Removes what the importer wrote to disk. Symlinks are removed, never
 * followed, so a link inside a temp folder cannot reach anything outside it.
 *
 * @author Tymofii Synianskyi
 */
final class FileTree {

	/**
	 * Removes a file, a symlink, or a folder with everything in it. A path
	 * that does not exist is not an error.
	 */
	public static function remove( string $path ): void {
		if ( is_link( $path ) || is_file( $path ) ) {
			wp_delete_file( $path );
			return;
		}

		if ( ! is_dir( $path ) ) {
			return;
		}

		$entries = scandir( $path );
		foreach ( false === $entries ? [] : $entries as $entry ) {
			if ( '.' !== $entry && '..' !== $entry ) {
				self::remove( $path . '/' . $entry );
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- A folder the importer created; WP_Filesystem is set up only around unzip_file().
		rmdir( $path );
	}
}
