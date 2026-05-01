<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Repositories;

use Brain\Monkey;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\WebinarRegistration\WebinarRegistration;
use VL\LMS\Domain\WebinarRegistration\WebinarRegistrationSource;
use VL\LMS\Domain\WebinarRegistration\WebinarRegistrationStatus;
use VL\LMS\Repositories\WebinarRegistrationRepository;

final class WebinarRegistrationRepositoryTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private WebinarRegistrationRepository $repo;

	/** @var Mockery\MockInterface */
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

		$this->repo = new WebinarRegistrationRepository();
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
	 * @param array<string, mixed> $overrides
	 *
	 * @return array<string, mixed>
	 */
	private static function row( array $overrides = [] ): array {
		return array_merge(
			[
				'id'                        => '1',
				'webinar_id'                => '500',
				'user_id'                   => '7',
				'status'                    => 'active',
				'source'                    => 'self_signup',
				'registered_at'             => '2026-04-20 09:00:00',
				'cancelled_at'              => null,
				'attended'                  => '0',
				'attended_duration_seconds' => '0',
				'created_at'                => '2026-04-20 09:00:00',
				'updated_at'                => '2026-04-20 09:00:00',
			],
			$overrides
		);
	}

	public function test_register_inserts_when_no_existing_row(): void {
		$captured_data = null;

		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static fn ( string $sql ): string => $sql
		);
		// First get_row: find() in register, returns null. Second: read-back after insert.
		$this->wpdb->shouldReceive( 'get_row' )
			->twice()
			->andReturnValues( [ null, self::row() ] );
		$this->wpdb->shouldReceive( 'insert' )
			->once()
			->andReturnUsing(
				function ( string $table, array $data ) use ( &$captured_data ): int {
					$captured_data = $data;
					return 1;
				}
			);
		$this->wpdb->insert_id = 1;

		$result = $this->repo->register(
			500,
			7,
			WebinarRegistrationSource::SELF_SIGNUP,
			self::utc( '2026-04-20 09:00:00' )
		);

		self::assertInstanceOf( WebinarRegistration::class, $result );
		self::assertSame( 'active', $captured_data['status'] );
		self::assertSame( 'self_signup', $captured_data['source'] );
		self::assertSame( '2026-04-20 09:00:00', $captured_data['registered_at'] );
	}

	public function test_register_flips_cancelled_back_to_active(): void {
		$captured_data = null;

		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static fn ( string $sql ): string => $sql
		);
		$this->wpdb->shouldReceive( 'get_row' )
			->twice()
			->andReturnValues(
				[
					self::row(
						[
							'status'       => 'cancelled',
							'cancelled_at' => '2026-04-21 09:00:00',
						]
					),
					self::row(
						[
							'status'       => 'active',
							'cancelled_at' => null,
						]
					),
				]
			);
		$this->wpdb->shouldReceive( 'update' )
			->once()
			->andReturnUsing(
				function ( string $table, array $data ) use ( &$captured_data ): int {
					$captured_data = $data;
					return 1;
				}
			);
		$this->wpdb->shouldNotReceive( 'insert' );

		$result = $this->repo->register(
			500,
			7,
			WebinarRegistrationSource::MANUAL,
			self::utc( '2026-04-22 09:00:00' )
		);

		self::assertSame( 'active', $captured_data['status'] );
		self::assertNull( $captured_data['cancelled_at'] );
		self::assertSame( 'manual', $captured_data['source'] );
		self::assertSame( WebinarRegistrationStatus::ACTIVE, $result->status );
	}

	public function test_cancel_sets_status_and_cancelled_at(): void {
		$captured_data = null;

		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static fn ( string $sql ): string => $sql
		);
		$this->wpdb->shouldReceive( 'get_row' )
			->twice()
			->andReturnValues(
				[
					self::row(),
					self::row(
						[
							'status'       => 'cancelled',
							'cancelled_at' => '2026-04-22 09:00:00',
						]
					),
				]
			);
		$this->wpdb->shouldReceive( 'update' )
			->once()
			->andReturnUsing(
				function ( string $table, array $data ) use ( &$captured_data ): int {
					$captured_data = $data;
					return 1;
				}
			);

		$result = $this->repo->cancel( 500, 7, self::utc( '2026-04-22 09:00:00' ) );

		self::assertNotNull( $result );
		self::assertSame( 'cancelled', $captured_data['status'] );
		self::assertSame( '2026-04-22 09:00:00', $captured_data['cancelled_at'] );
		self::assertSame( WebinarRegistrationStatus::CANCELLED, $result->status );
	}

	public function test_cancel_returns_null_when_no_row(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static fn ( string $sql ): string => $sql
		);
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( null );
		$this->wpdb->shouldNotReceive( 'update' );

		self::assertNull( $this->repo->cancel( 500, 7, self::utc( '2026-04-22 09:00:00' ) ) );
	}

	public function test_find_active_returns_null_for_cancelled_row(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static fn ( string $sql ): string => $sql
		);
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn(
			self::row( [ 'status' => 'cancelled' ] )
		);

		self::assertNull( $this->repo->find_active( 500, 7 ) );
	}

	public function test_find_active_returns_row_when_active(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static fn ( string $sql ): string => $sql
		);
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( self::row() );

		$result = $this->repo->find_active( 500, 7 );
		self::assertNotNull( $result );
		self::assertSame( WebinarRegistrationStatus::ACTIVE, $result->status );
	}

	public function test_count_active_for_webinar_uses_get_var_with_count_query(): void {
		$captured_sql  = null;
		$captured_args = [];

		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->andReturnUsing(
				function ( string $sql, ...$args ) use ( &$captured_sql, &$captured_args ): string {
					$captured_sql  = $sql;
					$captured_args = $args;
					return $sql;
				}
			);
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '12' );

		$result = $this->repo->count_active_for_webinar( 500 );

		self::assertSame( 12, $result );
		self::assertStringContainsString( 'COUNT(*)', $captured_sql );
		self::assertSame( [ 500, 'active' ], $captured_args );
	}

	public function test_mark_attended_accumulates_duration(): void {
		$captured_data = null;

		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static fn ( string $sql ): string => $sql
		);
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn(
			self::row( [ 'attended_duration_seconds' => '600' ] )
		);
		$this->wpdb->shouldReceive( 'update' )
			->once()
			->andReturnUsing(
				function ( string $table, array $data ) use ( &$captured_data ): int {
					$captured_data = $data;
					return 1;
				}
			);

		$this->repo->mark_attended( 500, 7, 300 );

		self::assertSame( 1, $captured_data['attended'] );
		self::assertSame( 900, $captured_data['attended_duration_seconds'] );
	}

	public function test_mark_attended_no_op_when_no_row(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static fn ( string $sql ): string => $sql
		);
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( null );
		$this->wpdb->shouldNotReceive( 'update' );

		$this->repo->mark_attended( 500, 7, 300 );
	}

	public function test_list_for_user_filters_by_status_when_supplied(): void {
		$captured_args = [];

		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->andReturnUsing(
				function ( string $sql, ...$args ) use ( &$captured_args ): string {
					$captured_args = $args;
					return $sql;
				}
			);
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( [] );

		$this->repo->list_for_user( 7, WebinarRegistrationStatus::ACTIVE );

		self::assertSame( [ 7, 'active' ], $captured_args );
	}
}
