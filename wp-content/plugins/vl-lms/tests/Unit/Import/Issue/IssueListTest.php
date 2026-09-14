<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Import\Issue;

use PHPUnit\Framework\TestCase;
use VL\LMS\Import\Issue\ImportIssue;
use VL\LMS\Import\Issue\IssueLevel;
use VL\LMS\Import\Issue\IssueList;

final class IssueListTest extends TestCase {

	public function test_an_empty_list_has_no_issues_and_no_errors(): void {
		$issues = new IssueList();

		self::assertSame( [], $issues->all() );
		self::assertSame( [], $issues->codes() );
		self::assertFalse( $issues->has_errors() );
	}

	public function test_all_sorts_by_line_keeps_insertion_order_for_equal_lines_and_puts_lineless_issues_first(): void {
		$issues = new IssueList();
		$issues->add( ImportIssue::error( 'c', 9, 'message' ) );
		$issues->add( ImportIssue::warning( 'a', 2, 'message' ) );
		$issues->add( ImportIssue::error( 'd', 9, 'message' ) );
		$issues->add( ImportIssue::error( 'z', null, 'message' ) );
		$issues->add( ImportIssue::warning( 'b', 2, 'message' ) );

		self::assertSame( [ 'z', 'a', 'b', 'c', 'd' ], $issues->codes() );
		self::assertSame(
			[ null, 2, 2, 9, 9 ],
			array_map( static fn ( ImportIssue $issue ): ?int => $issue->line, $issues->all() )
		);
	}

	public function test_has_errors_is_false_for_warnings_only_and_true_once_an_error_is_added(): void {
		$issues = new IssueList();
		$issues->add( ImportIssue::warning( 'structure.note', 3, 'message' ) );

		self::assertFalse( $issues->has_errors() );

		$issues->add( ImportIssue::error( 'structure.unknown_heading', 7, 'message' ) );

		self::assertTrue( $issues->has_errors() );
	}

	public function test_notes_never_count_as_errors(): void {
		$issues = new IssueList();
		$issues->add( ImportIssue::info( 'section.structure_ignored', 35, 'message' ) );
		$issues->add( ImportIssue::warning( 'taxonomy.new_term', 13, 'message' ) );

		self::assertFalse( $issues->has_errors() );
	}

	public function test_factories_set_the_level(): void {
		self::assertSame( IssueLevel::ERROR, ImportIssue::error( 'x', 1, 'm' )->level );
		self::assertSame( IssueLevel::WARNING, ImportIssue::warning( 'x', 1, 'm' )->level );
		self::assertSame( IssueLevel::INFO, ImportIssue::info( 'x', 1, 'm' )->level );
	}
}
