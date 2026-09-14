<?php

declare(strict_types=1);

namespace VL\LMS\Import\Write;

/**
 * Who and what one import run is for. The confirmation handler validates
 * these values (token ownership, instructor candidates) before the run;
 * the importer takes them as given.
 *
 * @author Tymofii Synianskyi
 */
final readonly class ImportContext {

	/**
	 * @param string $token         The import token, written to `_vl_import_id` on every created post.
	 * @param int    $instructor_id The lead instructor — `post_author` of every created post.
	 * @param int    $imported_by   The administrator who runs the import (`_vl_import_source.imported_by`).
	 */
	public function __construct(
		public string $token,
		public int $instructor_id,
		public int $imported_by
	) {
	}
}
