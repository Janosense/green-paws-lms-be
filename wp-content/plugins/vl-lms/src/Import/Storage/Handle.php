<?php

declare(strict_types=1);

namespace VL\LMS\Import\Storage;

/**
 * One upload's temp folder, as {@see TempStore} created or opened it.
 *
 * @author Tymofii Synianskyi
 */
final readonly class Handle {

	/**
	 * @param string $token The import token: 32 lowercase hex characters.
	 * @param string $dir   The token folder: absolute, without a trailing slash.
	 */
	public function __construct(
		public string $token,
		public string $dir
	) {
	}
}
