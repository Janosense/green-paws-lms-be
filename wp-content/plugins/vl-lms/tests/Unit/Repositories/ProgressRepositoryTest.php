<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Repositories;

use Brain\Monkey;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Progress\EntityType;
use VL\LMS\Domain\Progress\Progress;
use VL\LMS\Domain\Progress\ProgressStatus;
use VL\LMS\Repositories\ProgressRepository;

final class ProgressRepositoryTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private ProgressRepository $repo;

	/**
	 * @var Mockery\MockInterface
	 */
	private $wpdb;

	/** @var list<\DateTimeImmutable> */
	private array $clock_ticks = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WP core constant shim for tests.
		defined( 'ABSPATH' ) || define( 'ABSPATH', sys_get_temp_dir() . '/' );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WP core constant shim for tests.
		defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

		$this->wpdb         = Mockery::mock();
		$this->wpdb->prefix = 'wp_';
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test double for $wpdb.
		$GLOBALS['wpdb'] = $this->wpdb;

		$this->clock_ticks = [];
		$ticks             = &$this->clock_ticks;
		$this->repo        = new ProgressRepository(
			static function () use ( &$ticks ): \DateTimeImmutable {
				if ( [] === $ticks ) {
					return new \DateTimeImmutable( '2026-04-28 10:00:00', new \DateTimeZone( 'UTC' ) );
				}
				$next = array_shift( $ticks );
				return $next;
			}
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function row(
		int $id = 1,
		int $user_id = 5,
		int $entity_id = 101,
		int $course_id = 7,
		string $entity_type = 'lesson',
		string $status = 'in_progress',
		?string $completed_at = null,
		string $created_at = '2026-04-27 09:00:00',
		string $updated_at = '2026-04-28 10:00:00'
	): array {
		return [
			'id'               => (string) $id,
			'user_id'          => (string) $user_id,
			'entity_type'      => $entity_type,
			'entity_id'        => (string) $entity_id,
			'course_id'        => (string) $course_id,
			'status'           => $status,
			'position_seconds' => '42',
			'completed_at'     => $completed_at,
			'last_seen_at'     => '2026-04-28 10:00:00',
			'created_at'       => $created_at,
			'updated_at'       => $updated_at,
		];
	}

	public function test_find_passes_three_keys_to_prepare_and_hydrates_row(): void {
		$captured_args = [];

		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->andReturnUsing(
				function ( string $sql, ...$args ) use ( &$captured_args ): string {
					$captured_args = $args;
					return $sql;
				}
			);
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( self::row() );

		$result = $this->repo->find( 5, EntityType::LESSON, 101 );

		self::assertInstanceOf( Progress::class, $result );
		self::assertSame( [ 5, 'lesson', 101 ], $captured_args );
	}

	public function test_find_returns_null_when_no_row(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )->andReturn( null );

		self::assertNull( $this->repo->find( 5, EntityType::LESSON, 999 ) );
	}

	public function test_find_by_id_returns_progress(): void {
		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( self::row( 11 ) );

		$result = $this->repo->find_by_id( 11 );

		self::assertInstanceOf( Progress::class, $result );
		self::assertSame( 11, $result->id );
	}

	public function test_list_for_user_in_course_hydrates_each_row(): void {
		$captured_args = [];

		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->andReturnUsing(
				function ( string $sql, ...$args ) use ( &$captured_args ): string {
					$captured_args = $args;
					return $sql;
				}
			);
		$this->wpdb->shouldReceive( 'get_results' )
			->once()
			->andReturn(
				[
					self::row( 1, 5, 101 ),
					self::row( 2, 5, 102 ),
				]
			);

		$rows = $this->repo->list_for_user_in_course( 5, 7 );

		self::assertCount( 2, $rows );
		self::assertSame( [ 5, 7 ], $captured_args );
		self::assertSame( 101, $rows[0]->entity_id );
		self::assertSame( 102, $rows[1]->entity_id );
	}

	public function test_upsert_inserts_when_no_row_exists(): void {
		$this->clock_ticks = [
			new \DateTimeImmutable( '2026-04-28 10:00:00', new \DateTimeZone( 'UTC' ) ),
		];

		$captured_data = null;

		// SELECT for existing row -> nothing.
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )
			->ordered()
			->once()
			->andReturn( null );
		$this->wpdb->shouldReceive( 'insert' )
			->once()
			->andReturnUsing(
				function ( string $table, array $data ) use ( &$captured_data ): int {
					$captured_data = $data;
					return 1;
				}
			);
		$this->wpdb->insert_id = 42;
		// Re-fetch by id.
		$this->wpdb->shouldReceive( 'get_row' )
			->ordered()
			->once()
			->andReturn( self::row( 42, 5, 101, 7, 'lesson', 'in_progress', null, '2026-04-28 10:00:00', '2026-04-28 10:00:00' ) );

		$result = $this->repo->upsert(
			5,
			EntityType::LESSON,
			101,
			7,
			ProgressStatus::IN_PROGRESS,
			42,
			null,
			new \DateTimeImmutable( '2026-04-28 10:00:00', new \DateTimeZone( 'UTC' ) )
		);

		self::assertInstanceOf( Progress::class, $result );
		self::assertSame( 42, $result->id );

		self::assertSame( 5, $captured_data['user_id'] );
		self::assertSame( 'lesson', $captured_data['entity_type'] );
		self::assertSame( 101, $captured_data['entity_id'] );
		self::assertSame( 7, $captured_data['course_id'] );
		self::assertSame( 'in_progress', $captured_data['status'] );
		self::assertSame( 42, $captured_data['position_seconds'] );
		self::assertNull( $captured_data['completed_at'] );
		self::assertSame( '2026-04-28 10:00:00', $captured_data['last_seen_at'] );
		self::assertSame( '2026-04-28 10:00:00', $captured_data['created_at'] );
		self::assertSame( '2026-04-28 10:00:00', $captured_data['updated_at'] );
	}

	public function test_upsert_updates_existing_row_preserving_created_at_and_advancing_updated_at(): void {
		$this->clock_ticks = [
			new \DateTimeImmutable( '2026-04-28 12:00:00', new \DateTimeZone( 'UTC' ) ),
		];

		$captured_data  = null;
		$captured_where = null;

		$existing_row = self::row(
			55,
			5,
			101,
			7,
			'lesson',
			'in_progress',
			null,
			'2026-04-27 09:00:00',
			'2026-04-27 09:00:00'
		);

		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		// SELECT for existing row -> returns existing.
		$this->wpdb->shouldReceive( 'get_row' )
			->ordered()
			->once()
			->andReturn( $existing_row );
		$this->wpdb->shouldReceive( 'update' )
			->once()
			->andReturnUsing(
				function ( string $table, array $data, array $where ) use ( &$captured_data, &$captured_where ): int {
					$captured_data  = $data;
					$captured_where = $where;
					return 1;
				}
			);
		// Re-fetch by id.
		$this->wpdb->shouldReceive( 'get_row' )
			->ordered()
			->once()
			->andReturn(
				array_merge(
					$existing_row,
					[
						'status'     => 'completed',
						'updated_at' => '2026-04-28 12:00:00',
					]
				)
			);

		$result = $this->repo->upsert(
			5,
			EntityType::LESSON,
			101,
			7,
			ProgressStatus::COMPLETED,
			null,
			new \DateTimeImmutable( '2026-04-28 12:00:00', new \DateTimeZone( 'UTC' ) ),
			new \DateTimeImmutable( '2026-04-28 12:00:00', new \DateTimeZone( 'UTC' ) )
		);

		self::assertSame( 55, $result->id );
		self::assertSame( ProgressStatus::COMPLETED, $result->status );

		self::assertSame( [ 'id' => 55 ], $captured_where );
		self::assertSame( '2026-04-28 12:00:00', $captured_data['updated_at'] );
		self::assertArrayNotHasKey( 'created_at', $captured_data );
		self::assertSame( 'completed', $captured_data['status'] );
	}

	public function test_mark_completed_updates_status_completed_at_and_updated_at(): void {
		$this->clock_ticks = [
			new \DateTimeImmutable( '2026-04-28 13:00:00', new \DateTimeZone( 'UTC' ) ),
		];

		$captured_data = null;

		$this->wpdb->shouldReceive( 'update' )
			->once()
			->andReturnUsing(
				function ( string $table, array $data ) use ( &$captured_data ): int {
					$captured_data = $data;
					return 1;
				}
			);
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )
			->once()
			->andReturn(
				self::row( 7, 5, 101, 7, 'lesson', 'completed', '2026-04-28 13:00:00', '2026-04-27 09:00:00', '2026-04-28 13:00:00' )
			);

		$result = $this->repo->mark_completed(
			7,
			new \DateTimeImmutable( '2026-04-28 13:00:00', new \DateTimeZone( 'UTC' ) )
		);

		self::assertSame( ProgressStatus::COMPLETED, $result->status );
		self::assertSame( 'completed', $captured_data['status'] );
		self::assertSame( '2026-04-28 13:00:00', $captured_data['completed_at'] );
		self::assertSame( '2026-04-28 13:00:00', $captured_data['updated_at'] );
	}

	public function test_delete_for_user_returns_int_count_from_wpdb_delete(): void {
		$this->wpdb->shouldReceive( 'delete' )
			->once()
			->with( Mockery::any(), [ 'user_id' => 5 ] )
			->andReturn( 3 );

		self::assertSame( 3, $this->repo->delete_for_user( 5 ) );
	}

	public function test_delete_for_user_returns_zero_on_wpdb_failure(): void {
		$this->wpdb->shouldReceive( 'delete' )->once()->andReturn( false );

		self::assertSame( 0, $this->repo->delete_for_user( 5 ) );
	}
}
