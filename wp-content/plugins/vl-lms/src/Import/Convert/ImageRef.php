<?php

declare(strict_types=1);

namespace VL\LMS\Import\Convert;

/**
 * One `assets/…` image a course body references.
 *
 * The importer uploads the file behind `path` from the extracted archive and
 * rewrites every `src` equal to `src` to the attachment URL (Sprint 1 Step 6).
 *
 * @author Tymofii Synianskyi
 */
final readonly class ImageRef {

	/**
	 * @param string $src  The image URL as CommonMark writes it into `src` (percent-encoded), e.g. `assets/course/%D1%84.png`.
	 * @param string $path The decoded path inside the archive, e.g. `assets/course/ф.png`.
	 * @param string $alt  The image description as plain text.
	 * @param int    $line 1-based file line of the block that holds the image.
	 */
	public function __construct(
		public string $src,
		public string $path,
		public string $alt,
		public int $line
	) {
	}
}
