<?php

declare(strict_types=1);

namespace VL\LMS\Import\Storage;

/**
 * An accepted upload: its token folder, the stored `course.md` and the
 * images kept from the archive.
 *
 * @author Tymofii Synianskyi
 */
final readonly class IntakeResult {

	/**
	 * @param Handle       $handle         The upload's token folder.
	 * @param string       $course_md_path The stored `course.md`.
	 * @param list<string> $assets         Kept images, sorted, relative to the token folder (`assets/…`) — the form of `ImageRef::$path`.
	 */
	public function __construct(
		public Handle $handle,
		public string $course_md_path,
		public array $assets
	) {
	}
}
