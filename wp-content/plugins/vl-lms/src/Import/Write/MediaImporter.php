<?php

declare(strict_types=1);

namespace VL\LMS\Import\Write;

use RuntimeException;
use VL\LMS\Import\Convert\ImageRef;
use VL\LMS\Import\Issue\ImportIssue;
use VL\LMS\Import\Issue\IssueList;

/**
 * Puts the `assets/…` images a course references into the media library and
 * points the course HTML at them.
 *
 * An image is uploaded only when the import's folder holds it: its path must
 * be one of the regular files listed under the folder's `assets/`. Symlinks
 * are neither followed nor listed and no path is built from a reference, so a
 * reference cannot reach a file outside the folder. WordPress deletes the file
 * it sideloads, so it gets a copy — the folder keeps its files for a repeated
 * confirmation until the import is done.
 *
 * A referenced image the folder lacks is a warning and keeps its relative
 * `src`; a file in `assets/` nothing references is a warning and is not
 * uploaded (course-import FEATURE.md → Invariants). {@see self::check()} gives
 * the preview those warnings before anything is uploaded.
 *
 * @author Tymofii Synianskyi
 */
final class MediaImporter {

	public const IMAGE_MISSING = 'media.image_missing';
	public const IMAGE_UNUSED  = 'media.image_unused';

	private const IMPORT_ID_META = '_vl_import_id';

	private const ASSETS = 'assets';

	/**
	 * Uploads every referenced image the folder holds — attached to the course,
	 * authored by the lead instructor, marked with the import token — and
	 * records each attachment in the ledger as soon as WordPress creates it.
	 * The folder is compared with the images by {@see self::check()} first,
	 * which adds the missing-image and unused-file warnings.
	 *
	 * @param list<ImageRef> $images     The plan's images, one per path.
	 * @param string         $source_dir The folder that holds `course.md` and `assets/`.
	 *
	 * @return array<string, string> Archive path (`assets/…`) => attachment URL.
	 *
	 * @throws RuntimeException When an image cannot be uploaded; {@see Importer::run()} rolls back.
	 */
	public function import( array $images, string $source_dir, int $course_id, ImportContext $context, ImportLedger $ledger, IssueList $issues ): array {
		$found = $this->check( $images, $source_dir, $issues );
		$urls  = [];

		foreach ( $images as $image ) {
			if ( in_array( $image->path, $found, true ) ) {
				$urls[ $image->path ] = $this->upload( $image, $source_dir . '/' . $image->path, $course_id, $context, $ledger );
			}
		}

		return $urls;
	}

	/**
	 * Compares the images with the regular files under the folder's `assets/`
	 * without uploading anything: warns about each referenced image the folder
	 * lacks and each file nothing references. The import preview shows these
	 * warnings; {@see self::import()} uploads the paths returned.
	 *
	 * @param list<ImageRef> $images     The plan's images, one per path.
	 * @param string         $source_dir The folder that holds `course.md` and `assets/`.
	 *
	 * @return list<string> The referenced paths the folder holds, in plan order.
	 */
	public function check( array $images, string $source_dir, IssueList $issues ): array {
		$files      = $this->files( $source_dir );
		$referenced = [];
		$found      = [];

		foreach ( $images as $image ) {
			$referenced[ $image->path ] = true;

			if ( in_array( $image->path, $files, true ) ) {
				$found[] = $image->path;
				continue;
			}

			$issues->add(
				ImportIssue::warning(
					self::IMAGE_MISSING,
					$image->line,
					/* translators: %s: image path inside the course archive, e.g. "assets/course/scheme.png" */
					sprintf( __( 'Зображення «%s» немає серед завантажених файлів курсу, тому в тексті лишилося посилання на відсутній файл.', 'vl-lms' ), $image->path )
				)
			);
		}

		foreach ( $files as $path ) {
			if ( ! isset( $referenced[ $path ] ) ) {
				$issues->add(
					ImportIssue::warning(
						self::IMAGE_UNUSED,
						null,
						/* translators: %s: file path inside the course archive, e.g. "assets/course/scheme.png" */
						sprintf( __( 'Файл «%s» ніде в курсі не використано, тому його не додано до медіатеки.', 'vl-lms' ), $path )
					)
				);
			}
		}

		return $found;
	}

	/**
	 * Points every `<img src>` of an uploaded image at its attachment URL.
	 *
	 * A `src` is matched by the archive path it names: its value decoded the
	 * way CommonMark encoded it — attribute escaping, then percent-encoding —
	 * which is how `MarkdownToHtml` derives `ImageRef::$path`. Any other `src`
	 * (an absolute URL, a missing image) and everything outside `<img>` tags
	 * stay as they are. CommonMark escapes `"` in text and the importer strips
	 * raw HTML, so every `<img` in a body is an image CommonMark rendered.
	 *
	 * @param array<string, string> $urls Archive path => attachment URL, from {@see self::import()}.
	 *
	 * @throws RuntimeException When the HTML cannot be searched.
	 */
	public function rewrite( string $html, array $urls ): string {
		$rewritten = preg_replace_callback(
			'/(<img\s[^>]*?\bsrc=")([^"]*)(")/',
			static function ( array $attribute ) use ( $urls ): string {
				$path = rawurldecode( htmlspecialchars_decode( $attribute[2], ENT_QUOTES ) );

				return isset( $urls[ $path ] ) ? $attribute[1] . esc_url( $urls[ $path ] ) . $attribute[3] : $attribute[0];
			},
			$html
		);

		if ( null === $rewritten ) {
			$this->fail( __( 'Не вдалося замінити адреси зображень у тексті курсу.', 'vl-lms' ) );
		}

		return $rewritten;
	}

	/**
	 * Sideloads a copy of one image and returns its attachment URL.
	 *
	 * @throws RuntimeException When the copy, the sideload or the URL fails.
	 */
	private function upload( ImageRef $image, string $file, int $course_id, ImportContext $context, ImportLedger $ledger ): string {
		$this->load_media_api();

		$name  = wp_basename( $image->path );
		$alt   = trim( $image->alt );
		$title = '' !== $alt ? $alt : (string) preg_replace( '/\.[^.]+$/', '', $name );
		$copy  = wp_tempnam( $name );

		try {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A failed copy is reported below as the failure reason, as _wp_handle_upload() does.
			if ( ! @copy( $file, $copy ) ) {
				/* translators: %s: image path inside the course archive, e.g. "assets/course/scheme.png" */
				$this->fail( sprintf( __( 'Не вдалося підготувати зображення «%s» до завантаження в медіатеку.', 'vl-lms' ), $image->path ) );
			}

			$id = media_handle_sideload(
				[
					'name'     => $name,
					'tmp_name' => $copy,
				],
				$course_id,
				wp_slash( $title ),
				wp_slash(
					[
						'post_author' => $context->instructor_id,
						'meta_input'  => [ self::IMPORT_ID_META => $context->token ],
					]
				)
			);
		} finally {
			// WordPress deletes the copy it moves; one it refused is still here.
			if ( file_exists( $copy ) ) {
				wp_delete_file( $copy );
			}
		}

		if ( is_wp_error( $id ) ) {
			$this->fail( $id->get_error_message() );
		}

		$ledger->record_attachment( $id, $title );

		$url = wp_get_attachment_url( $id );
		if ( false === $url ) {
			/* translators: %s: image path inside the course archive, e.g. "assets/course/scheme.png" */
			$this->fail( sprintf( __( 'WordPress не повернув адресу зображення «%s».', 'vl-lms' ), $image->path ) );
		}

		return $url;
	}

	/**
	 * The regular files under the folder's `assets/`, at any depth, as sorted
	 * paths `assets/…` — the form of `ImageRef::$path`.
	 *
	 * @return list<string>
	 */
	private function files( string $source_dir ): array {
		$files = [];
		$this->collect( $source_dir, self::ASSETS, $files );
		sort( $files, SORT_STRING );

		return $files;
	}

	/**
	 * @param list<string> $files Collects the paths found.
	 */
	private function collect( string $source_dir, string $relative, array &$files ): void {
		$path = $source_dir . '/' . $relative;

		if ( is_link( $path ) ) {
			return;
		}

		if ( is_file( $path ) ) {
			$files[] = $relative;
			return;
		}

		if ( ! is_dir( $path ) ) {
			return;
		}

		$entries = scandir( $path );
		foreach ( false === $entries ? [] : $entries as $entry ) {
			if ( '.' !== $entry && '..' !== $entry ) {
				$this->collect( $source_dir, $relative . '/' . $entry, $files );
			}
		}
	}

	/**
	 * `media_handle_sideload()`, `wp_tempnam()` and the image functions they use
	 * live in admin includes: `admin-post.php` loads them, `wp eval-file` does not.
	 */
	private function load_media_api(): void {
		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
	}

	/**
	 * Aborts the import; {@see Importer::run()} rolls back and returns the message as the failure reason.
	 *
	 * @throws RuntimeException Always.
	 */
	private function fail( string $message ): never {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Caught in Importer::run() and carried as ImportResult::$reason data; the report screen escapes it.
		throw new RuntimeException( $message );
	}
}
