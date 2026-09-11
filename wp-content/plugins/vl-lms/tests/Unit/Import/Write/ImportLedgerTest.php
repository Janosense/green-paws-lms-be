<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Import\Write;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Import\Issue\IssueList;
use VL\LMS\Import\Write\ImportLedger;
use VL\LMS\Import\Write\ImportResult;

final class ImportLedgerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * @var list<string>
	 */
	private array $deletes = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->deletes = [];
		Functions\when( 'wp_delete_post' )->alias(
			function ( int $id, bool $force ): object {
				$this->deletes[] = 'post ' . $id . ( $force ? ' force' : '' );

				return Mockery::mock( 'WP_Post' );
			}
		);
		Functions\when( 'wp_delete_attachment' )->alias(
			function ( int $id, bool $force ): object {
				$this->deletes[] = 'attachment ' . $id . ( $force ? ' force' : '' );

				return Mockery::mock( 'WP_Post' );
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_summary_lists_posts_and_attachments_in_creation_order(): void {
		$ledger = $this->ledger();

		self::assertSame(
			[
				[
					'type'  => 'vl_course',
					'id'    => 101,
					'title' => 'Курс',
				],
				[
					'type'  => 'attachment',
					'id'    => 102,
					'title' => 'monitor.png',
				],
				[
					'type'  => 'vl_module',
					'id'    => 103,
					'title' => 'Модуль',
				],
				[
					'type'  => 'vl_lesson',
					'id'    => 104,
					'title' => 'Урок',
				],
			],
			$ledger->summary()
		);
	}

	public function test_rollback_force_deletes_newest_first_attachments_with_their_files(): void {
		$leftovers = $this->ledger()->rollback();

		self::assertSame( [ 'post 104 force', 'post 103 force', 'attachment 102 force', 'post 101 force' ], $this->deletes );
		self::assertSame( [], $leftovers );
	}

	public function test_rollback_reports_what_it_could_not_delete(): void {
		Functions\when( 'wp_delete_post' )->alias(
			static fn ( int $id ): mixed => 103 === $id ? false : ( 101 === $id ? null : Mockery::mock( 'WP_Post' ) )
		);
		Functions\when( 'wp_delete_attachment' )->justReturn( false );

		self::assertSame( [ 103, 102, 101 ], $this->ledger()->rollback() );
	}

	public function test_rollback_empties_the_ledger(): void {
		$ledger = $this->ledger();
		$ledger->rollback();
		$this->deletes = [];

		self::assertSame( [], $ledger->rollback() );
		self::assertSame( [], $this->deletes );
		self::assertSame( [], $ledger->summary() );
	}

	public function test_an_empty_ledger_rolls_back_nothing(): void {
		self::assertSame( [], ( new ImportLedger() )->rollback() );
		self::assertSame( [], $this->deletes );
	}

	public function test_results_carry_either_the_created_tree_or_the_failure(): void {
		$issues  = new IssueList();
		$created = ImportResult::created( 101, $this->ledger()->summary(), $issues );
		$failed  = ImportResult::failed( 'Database error.', [ 103 ] );

		self::assertTrue( $created->created );
		self::assertSame( 101, $created->course_id );
		self::assertCount( 4, $created->entities );
		self::assertSame( $issues, $created->issues );
		self::assertNull( $created->reason );

		self::assertFalse( $failed->created );
		self::assertNull( $failed->course_id );
		self::assertSame( [], $failed->entities );
		self::assertSame( 'Database error.', $failed->reason );
		self::assertSame( [ 103 ], $failed->leftovers );
	}

	private function ledger(): ImportLedger {
		$ledger = new ImportLedger();
		$ledger->record_post( 'vl_course', 101, 'Курс' );
		$ledger->record_attachment( 102, 'monitor.png' );
		$ledger->record_post( 'vl_module', 103, 'Модуль' );
		$ledger->record_post( 'vl_lesson', 104, 'Урок' );

		return $ledger;
	}
}
