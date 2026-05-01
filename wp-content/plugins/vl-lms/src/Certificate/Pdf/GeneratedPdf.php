<?php

declare(strict_types=1);

namespace VL\LMS\Certificate\Pdf;

/**
 * Tuple-like return value from {@see PdfGenerator::generate()}.
 *
 * `absolute_path` is the on-disk filesystem location (used by the
 * download controller for `readfile`); `relative_path` is what gets
 * written to `vl_certificates.pdf_path` so the row survives a move of
 * `wp-content/uploads`. `cache_hit` is `true` when the file was already
 * present on disk — the controller uses this to decide whether to call
 * `update_pdf_path` on the repo.
 *
 * @author Tymofii Synianskyi
 */
final readonly class GeneratedPdf {

	public function __construct(
		public string $absolute_path,
		public string $relative_path,
		public bool $cache_hit
	) {
	}
}
