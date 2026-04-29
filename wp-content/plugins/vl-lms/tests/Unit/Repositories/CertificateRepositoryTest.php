<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Repositories;

use Brain\Monkey;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Certificate\Certificate;
use VL\LMS\Repositories\CertificateRepository;

final class CertificateRepositoryTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private CertificateRepository $repo;

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
		$this->repo        = new CertificateRepository(
			static function () use ( &$ticks ): \DateTimeImmutable {
				if ( [] === $ticks ) {
					return new \DateTimeImmutable( '2026-04-28 10:00:00', new \DateTimeZone( 'UTC' ) );
				}
				return array_shift( $ticks );
			}
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	private static function utc( string $value ): \DateTimeImmutable {
		return new \DateTimeImmutable( $value, new \DateTimeZone( 'UTC' ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function row(
		int $id = 11,
		string $uuid = '8e2c4d2a-0000-4000-8000-000000000001',
		?string $revoked_at = null,
		?string $pdf_path = null
	): array {
		return [
			'id'              => (string) $id,
			'uuid'            => $uuid,
			'user_id'         => '5',
			'course_id'       => '7',
			'enrollment_id'   => '21',
			'issued_at'       => '2026-04-28 10:00:00',
			'revoked_at'      => $revoked_at,
			'final_score'     => '85',
			'final_max_score' => '100',
			'snapshot_data'   => '{"course_title":"Course"}',
			'pdf_path'        => $pdf_path,
			'created_at'      => '2026-04-28 10:00:00',
			'updated_at'      => '2026-04-28 10:00:00',
		];
	}

	public function test_find_returns_certificate_for_existing_id(): void {
		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( self::row() );

		$result = $this->repo->find( 11 );

		self::assertInstanceOf( Certificate::class, $result );
		self::assertSame( 11, $result->id );
	}

	public function test_find_returns_null_when_no_row(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )->andReturn( null );

		self::assertNull( $this->repo->find( 999 ) );
	}

	public function test_find_by_uuid_passes_uuid_to_prepare(): void {
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

		$result = $this->repo->find_by_uuid( '8e2c4d2a-0000-4000-8000-000000000001' );

		self::assertInstanceOf( Certificate::class, $result );
		self::assertSame( [ '8e2c4d2a-0000-4000-8000-000000000001' ], $captured_args );
	}

	public function test_find_active_for_user_in_course_filters_revoked_at_null(): void {
		$captured_sql = '';

		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->andReturnUsing(
				function ( string $sql ) use ( &$captured_sql ): string {
					$captured_sql = $sql;
					return $sql;
				}
			);
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( self::row() );

		$this->repo->find_active_for_user_in_course( 5, 7 );

		self::assertStringContainsString( 'revoked_at IS NULL', $captured_sql );
	}

	public function test_list_for_user_includes_active_and_revoked(): void {
		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_results' )
			->once()
			->andReturn(
				[
					self::row( 1 ),
					self::row( 2, '8e2c4d2a-0000-4000-8000-000000000002', '2026-04-29 09:00:00' ),
				]
			);

		$rows = $this->repo->list_for_user( 5 );

		self::assertCount( 2, $rows );
		self::assertNull( $rows[0]->revoked_at );
		self::assertNotNull( $rows[1]->revoked_at );
	}

	public function test_insert_writes_row_with_audit_columns_and_returns_insert_id(): void {
		$this->clock_ticks = [ self::utc( '2026-04-28 10:00:00' ) ];

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

		$cert = new Certificate(
			0,
			'8e2c4d2a-0000-4000-8000-000000000001',
			5,
			7,
			21,
			self::utc( '2026-04-28 10:00:00' ),
			null,
			85,
			100,
			[ 'course_title' => 'Course' ],
			null,
			self::utc( '2026-04-28 10:00:00' ),
			self::utc( '2026-04-28 10:00:00' )
		);

		$id = $this->repo->insert( $cert );

		self::assertSame( 11, $id );
		self::assertArrayNotHasKey( 'id', $captured_data );
		self::assertSame( '8e2c4d2a-0000-4000-8000-000000000001', $captured_data['uuid'] );
		self::assertSame( 5, $captured_data['user_id'] );
		self::assertSame( 7, $captured_data['course_id'] );
		self::assertSame( 21, $captured_data['enrollment_id'] );
		self::assertSame( '{"course_title":"Course"}', $captured_data['snapshot_data'] );
		self::assertNull( $captured_data['revoked_at'] );
		self::assertSame( '2026-04-28 10:00:00', $captured_data['created_at'] );
	}

	public function test_update_revocation_writes_timestamp_when_provided(): void {
		$this->clock_ticks = [ self::utc( '2026-04-29 09:00:00' ) ];

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

		$ok = $this->repo->update_revocation( 11, self::utc( '2026-04-29 09:00:00' ) );

		self::assertTrue( $ok );
		self::assertSame( [ 'id' => 11 ], $captured_where );
		self::assertSame( '2026-04-29 09:00:00', $captured_data['revoked_at'] );
		self::assertSame( '2026-04-29 09:00:00', $captured_data['updated_at'] );
	}

	public function test_update_revocation_clears_timestamp_when_null(): void {
		$this->clock_ticks = [ self::utc( '2026-04-29 10:00:00' ) ];

		$captured_data = null;

		$this->wpdb->shouldReceive( 'update' )
			->once()
			->andReturnUsing(
				function ( string $table, array $data ) use ( &$captured_data ): int {
					$captured_data = $data;
					return 1;
				}
			);

		$ok = $this->repo->update_revocation( 11, null );

		self::assertTrue( $ok );
		self::assertNull( $captured_data['revoked_at'] );
	}

	public function test_update_pdf_path_writes_path_and_updated_at(): void {
		$this->clock_ticks = [ self::utc( '2026-04-28 11:00:00' ) ];

		$captured_data = null;

		$this->wpdb->shouldReceive( 'update' )
			->once()
			->andReturnUsing(
				function ( string $table, array $data ) use ( &$captured_data ): int {
					$captured_data = $data;
					return 1;
				}
			);

		$ok = $this->repo->update_pdf_path( 11, 'certificates/2026/cert-abc.pdf' );

		self::assertTrue( $ok );
		self::assertSame( 'certificates/2026/cert-abc.pdf', $captured_data['pdf_path'] );
		self::assertSame( '2026-04-28 11:00:00', $captured_data['updated_at'] );
	}

	public function test_update_returns_false_when_wpdb_fails(): void {
		$this->wpdb->shouldReceive( 'update' )->once()->andReturn( false );

		self::assertFalse( $this->repo->update_pdf_path( 11, 'whatever.pdf' ) );
	}
}
