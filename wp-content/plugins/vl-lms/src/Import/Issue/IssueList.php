<?php

declare(strict_types=1);

namespace VL\LMS\Import\Issue;

/**
 * Collector the parsers (and later the validator) append their issues to.
 *
 * Issues are reported in file order regardless of which pass found them:
 * `all()` sorts by line, keeps insertion order for equal lines (PHP's sort is
 * stable) and puts issues without a line first.
 *
 * @author Tymofii Synianskyi
 */
final class IssueList {

	/**
	 * @var list<ImportIssue>
	 */
	private array $issues = [];

	public function add( ImportIssue $issue ): void {
		$this->issues[] = $issue;
	}

	/**
	 * @return list<ImportIssue>
	 */
	public function all(): array {
		$issues = $this->issues;
		usort(
			$issues,
			static fn ( ImportIssue $a, ImportIssue $b ): int => ( $a->line ?? 0 ) <=> ( $b->line ?? 0 )
		);

		return $issues;
	}

	public function has_errors(): bool {
		foreach ( $this->issues as $issue ) {
			if ( IssueLevel::ERROR === $issue->level ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The issue codes in the order of {@see all()}.
	 *
	 * @return list<string>
	 */
	public function codes(): array {
		return array_map( static fn ( ImportIssue $issue ): string => $issue->code, $this->all() );
	}
}
