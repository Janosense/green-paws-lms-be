<?php

declare(strict_types=1);

namespace VL\LMS\Import\Plan;

/**
 * The part of `_vl_import_source` that comes from the file. `imported_at`
 * and `imported_by` belong to the run and are added when the course is
 * written, so analysing the same file always gives the same plan.
 *
 * @author Tymofii Synianskyi
 */
final readonly class ImportSource {

	public function __construct(
		public string $slug,
		public int $version,
		public string $status,
		public string $author,
		public ?string $author_org,
		public ?string $source_type,
		public ?string $source_title,
		public ?string $source_date
	) {
	}
}
