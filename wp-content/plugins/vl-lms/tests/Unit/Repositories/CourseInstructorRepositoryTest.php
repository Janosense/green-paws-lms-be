<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Repositories;

use Brain\Monkey;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\CourseInstructor\CourseInstructor;
use VL\LMS\Domain\CourseInstructor\InstructorEntityType;
use VL\LMS\Repositories\CourseInstructorRepository;

final class CourseInstructorRepositoryTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private CourseInstructorRepository $repo;

	/**
	 * @var Mockery\MockInterface
	 */
	private $wpdb;

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

		$this->repo = new CourseInstructorRepository();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function row( int $id = 11 ): array {
		return [
			'id'             => (string) $id,
			'entity_type'    => 'course',
			'entity_id'      => '123',
			'user_id'        => '7',
			'role_in_course' => 'lead',
			'display_order'  => '0',
			'assigned_at'    => '2026-04-23 10:00:00',
			'assigned_by'    => '1',
		];
	}

	public function test_find_by_id_hydrates_row(): void {
		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturnUsing( static fn ( string $sql ): string => $sql );
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( self::row() );

		$result = $this->repo->find_by_id( 11 );

		self::assertInstanceOf( CourseInstructor::class, $result );
		self::assertSame( 11, $result->id );
	}

	public function test_find_assignment_passes_all_keys_to_prepare(): void {
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

		$result = $this->repo->find_assignment( InstructorEntityType::COURSE, 123, 7 );

		self::assertInstanceOf( CourseInstructor::class, $result );
		self::assertSame( [ 'course', 123, 7 ], $captured_args );
	}

	public function test_list_for_entity_includes_order_by_clause(): void {
		$captured_sql = null;

		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->andReturnUsing(
				function ( string $sql ) use ( &$captured_sql ): string {
					$captured_sql = $sql;
					return $sql;
				}
			);
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( [ self::row() ] );

		$rows = $this->repo->list_for_entity( InstructorEntityType::COURSE, 123 );

		self::assertCount( 1, $rows );
		self::assertStringContainsString( 'ORDER BY display_order ASC, id ASC', $captured_sql );
	}

	public function test_find_lead_filters_by_role_and_limits(): void {
		$captured_sql = null;

		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->andReturnUsing(
				function ( string $sql ) use ( &$captured_sql ): string {
					$captured_sql = $sql;
					return $sql;
				}
			);
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( self::row() );

		$result = $this->repo->find_lead( InstructorEntityType::COURSE, 123 );

		self::assertInstanceOf( CourseInstructor::class, $result );
		self::assertStringContainsString( "role_in_course = 'lead'", $captured_sql );
		self::assertStringContainsString( 'LIMIT 1', $captured_sql );
	}

	public function test_is_assigned_uses_select_one_and_limit(): void {
		$captured_sql = null;

		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->andReturnUsing(
				function ( string $sql ) use ( &$captured_sql ): string {
					$captured_sql = $sql;
					return $sql;
				}
			);
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '1' );

		$result = $this->repo->is_assigned( InstructorEntityType::COURSE, 123, 7 );

		self::assertTrue( $result );
		self::assertStringContainsString( 'SELECT 1', $captured_sql );
		self::assertStringContainsString( 'LIMIT 1', $captured_sql );
	}

	public function test_is_assigned_returns_false_when_no_row(): void {
		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturnUsing( static fn ( string $sql ): string => $sql );
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( null );

		self::assertFalse( $this->repo->is_assigned( InstructorEntityType::COURSE, 123, 7 ) );
	}

	public function test_insert_auto_fills_assigned_at_when_absent(): void {
		$captured_data = null;

		$this->wpdb->shouldReceive( 'insert' )
			->once()
			->andReturnUsing(
				function ( string $table, array $data ) use ( &$captured_data ): int {
					$captured_data = $data;
					return 1;
				}
			);
		$this->wpdb->insert_id = 11;

		$id = $this->repo->insert(
			[
				'entity_type'    => 'course',
				'entity_id'      => 123,
				'user_id'        => 7,
				'role_in_course' => 'lead',
				'display_order'  => 0,
				'assigned_by'    => 1,
			]
		);

		self::assertSame( 11, $id );
		self::assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $captured_data['assigned_at'] );
	}

	public function test_insert_preserves_caller_supplied_assigned_at(): void {
		$captured_data = null;

		$this->wpdb->shouldReceive( 'insert' )
			->once()
			->andReturnUsing(
				function ( string $table, array $data ) use ( &$captured_data ): int {
					$captured_data = $data;
					return 1;
				}
			);
		$this->wpdb->insert_id = 11;

		$this->repo->insert(
			[
				'entity_type'    => 'course',
				'entity_id'      => 123,
				'user_id'        => 7,
				'role_in_course' => 'lead',
				'display_order'  => 0,
				'assigned_at'    => '2020-01-01 00:00:00',
				'assigned_by'    => 1,
			]
		);

		self::assertSame( '2020-01-01 00:00:00', $captured_data['assigned_at'] );
	}

	public function test_update_delegates_to_wpdb_update(): void {
		$captured_data  = null;
		$captured_where = null;

		$this->wpdb->shouldReceive( 'update' )
			->once()
			->andReturnUsing(
				function ( string $table, array $data, array $where ) use ( &$captured_data, &$captured_where ): int {
					$captured_data  = $data;
					$captured_where = $where;
					return 1;
				}
			);

		$ok = $this->repo->update( 11, [ 'role_in_course' => 'co_instructor' ] );

		self::assertTrue( $ok );
		self::assertSame( [ 'role_in_course' => 'co_instructor' ], $captured_data );
		self::assertSame( [ 'id' => 11 ], $captured_where );
	}

	public function test_delete_returns_false_on_wpdb_failure(): void {
		$this->wpdb->shouldReceive( 'delete' )->once()->andReturn( false );

		self::assertFalse( $this->repo->delete( 11 ) );
	}

	public function test_delete_all_for_entity_returns_deleted_count(): void {
		$captured_where = null;

		$this->wpdb->shouldReceive( 'delete' )
			->once()
			->andReturnUsing(
				function ( string $table, array $where ) use ( &$captured_where ): int {
					$captured_where = $where;
					return 3;
				}
			);

		$count = $this->repo->delete_all_for_entity( InstructorEntityType::COURSE, 123 );

		self::assertSame( 3, $count );
		self::assertSame(
			[
				'entity_type' => 'course',
				'entity_id'   => 123,
			],
			$captured_where
		);
	}

	public function test_delete_all_for_entity_returns_zero_on_failure(): void {
		$this->wpdb->shouldReceive( 'delete' )->once()->andReturn( false );

		self::assertSame( 0, $this->repo->delete_all_for_entity( InstructorEntityType::COURSE, 123 ) );
	}
}
