<?php

declare(strict_types=1);

namespace VL\LMS\Import\Write;

use VL\LMS\Import\Issue\IssueList;

/**
 * The outcome of one import run: the created course tree, or the reason
 * nothing was kept.
 *
 * @author Tymofii Synianskyi
 */
final readonly class ImportResult {

	/**
	 * @param bool                                              $created   True when the whole course tree was written.
	 * @param int|null                                          $course_id The created `vl_course`.
	 * @param list<array{type: string, id: int, title: string}> $entities  Everything created, in creation order.
	 * @param IssueList                                         $issues    The plan's warnings and notes plus the importer's warnings.
	 * @param string|null                                       $code      Why the run failed, as a stable code; null when it did not.
	 * @param string|null                                       $reason    Why the run failed.
	 * @param list<int>                                         $leftovers Ids the rollback could not delete; empty when nothing survived.
	 */
	private function __construct(
		public bool $created,
		public ?int $course_id,
		public array $entities,
		public IssueList $issues,
		public ?string $code,
		public ?string $reason,
		public array $leftovers
	) {
	}

	/**
	 * @param list<array{type: string, id: int, title: string}> $entities
	 */
	public static function created( int $course_id, array $entities, IssueList $issues ): self {
		return new self( true, $course_id, $entities, $issues, null, null, [] );
	}

	/**
	 * The code is what a screen may show — `$reason` is WordPress's own text and
	 * belongs in the log, never in a URL (`docs/DECISIONS.md` 2026-09-12).
	 *
	 * @param string    $code      A stable code of the class that failed the run.
	 * @param list<int> $leftovers
	 */
	public static function failed( string $code, string $reason, array $leftovers ): self {
		return new self( false, null, [], new IssueList(), $code, $reason, $leftovers );
	}
}
