<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\StudyTime\Repositories;

use Brain\Monkey;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\StudyTime\Domain\StudyKind;
use VL\LMS\StudyTime\Domain\StudyTimeEntry;
use VL\LMS\StudyTime\Repositories\StudyTimeRepository;

/**
 * `prepare()` binds positionally and this double substitutes the binds in
 * source order, so every assertion reads the SQL MySQL would receive — an
 * args-array assertion would pass with the binds shifted (`docs/TESTING.md`).
 */
final class StudyTimeRepositoryTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private StudyTimeRepository $repo;

	/**
	 * @var Mockery\MockInterface
	 */
	private $wpdb;

	/**
	 * The SQL of the last `prepare()` call, binds substituted.
	 */
	private ?string $prepared = null;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WP core constant shim for tests.
		defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

		$this->wpdb         = Mockery::mock();
		$this->wpdb->prefix = 'wp_';
		$this->wpdb->shouldReceive( 'prepare' )
			->andReturnUsing(
				function ( string $sql, ...$args ): string {
					$this->prepared = (string) preg_replace_callback(
						'/%[ds]/',
						static function () use ( &$args ): string {
							$next = array_shift( $args );
							return is_string( $next ) ? "'" . $next . "'" : (string) $next;
						},
						$sql
					);
					return $this->prepared;
				}
			);
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test double for $wpdb.
		$GLOBALS['wpdb'] = $this->wpdb;

		$this->repo = new StudyTimeRepository();
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
	private static function row( int $id, string $kind, int $seconds ): array {
		return [
			'id'              => (string) $id,
			'user_id'         => '7',
			'course_id'       => '42',
			'entity_type'     => 'lesson',
			'entity_id'       => '101',
			'kind'            => $kind,
			'active_seconds'  => (string) $seconds,
			'first_signal_at' => '2026-09-15 10:00:00',
			'last_signal_at'  => '2026-09-15 10:00:30',
		];
	}

	public function test_add_seconds_upserts_on_the_unique_key_and_increments_in_one_statement(): void {
		$this->wpdb->shouldReceive( 'query' )->once()->andReturn( 1 );
		$this->wpdb->shouldNotReceive( 'get_var', 'get_row', 'get_results' );

		$this->repo->add_seconds( 7, 42, 'lesson', 101, StudyKind::VIDEO, 30, self::utc( '2026-09-15 10:00:30' ) );

		self::assertSame(
			'INSERT INTO wp_vl_study_time (user_id, course_id, entity_type, entity_id, kind, active_seconds, first_signal_at, last_signal_at) '
			. "VALUES (7, 42, 'lesson', 101, 'video', 30, '2026-09-15 10:00:30', '2026-09-15 10:00:30') "
			. "ON DUPLICATE KEY UPDATE active_seconds = active_seconds + 30, last_signal_at = '2026-09-15 10:00:30'",
			$this->prepared
		);
	}

	public function test_add_seconds_on_a_first_signal_inserts_zero_and_never_rewrites_first_signal_at(): void {
		$this->wpdb->shouldReceive( 'query' )->once()->andReturn( 1 );

		$this->repo->add_seconds( 7, 42, 'topic', 205, StudyKind::READING, 0, self::utc( '2026-09-15 10:00:00' ) );

		[ $insert, $update ] = explode( 'ON DUPLICATE KEY UPDATE', (string) $this->prepared );
		self::assertStringContainsString( "VALUES (7, 42, 'topic', 205, 'reading', 0, '2026-09-15 10:00:00', '2026-09-15 10:00:00')", $insert );
		self::assertStringNotContainsString( 'first_signal_at', $update );
	}

	public function test_add_seconds_stores_now_in_utc_whatever_its_zone(): void {
		$this->wpdb->shouldReceive( 'query' )->once()->andReturn( 1 );

		$kyiv = new \DateTimeImmutable( '2026-09-15 13:00:30', new \DateTimeZone( 'Europe/Kyiv' ) );
		$this->repo->add_seconds( 7, 42, 'lesson', 101, StudyKind::VIDEO, 30, $kyiv );

		self::assertStringContainsString( "last_signal_at = '2026-09-15 10:00:30'", (string) $this->prepared );
		self::assertStringNotContainsString( '13:00:30', (string) $this->prepared );
	}

	public function test_last_signal_at_for_user_in_course_reads_the_max_of_one_course_only(): void {
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '2026-09-15 10:00:30' );

		$result = $this->repo->last_signal_at_for_user_in_course( 7, 42 );

		self::assertSame( 'SELECT MAX(last_signal_at) FROM wp_vl_study_time WHERE user_id = 7 AND course_id = 42', $this->prepared );
		self::assertInstanceOf( \DateTimeImmutable::class, $result );
		self::assertSame( '2026-09-15T10:00:30+00:00', $result->format( DATE_ATOM ) );
	}

	public function test_last_signal_at_for_user_in_course_is_null_without_rows(): void {
		// MAX() over no rows yields one NULL row.
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( null );

		self::assertNull( $this->repo->last_signal_at_for_user_in_course( 7, 42 ) );
	}

	public function test_rows_for_user_in_course_filters_user_and_course_and_hydrates_entries(): void {
		$this->wpdb->shouldReceive( 'get_results' )
			->once()
			->with( Mockery::type( 'string' ), ARRAY_A )
			->andReturn( [ self::row( 3, 'video', 90 ), self::row( 4, 'reading', 30 ) ] );

		$rows = $this->repo->rows_for_user_in_course( 7, 42 );

		self::assertSame( 'SELECT * FROM wp_vl_study_time WHERE user_id = 7 AND course_id = 42 ORDER BY id ASC', $this->prepared );
		self::assertCount( 2, $rows );
		self::assertContainsOnlyInstancesOf( StudyTimeEntry::class, $rows );
		self::assertSame( [ 3, 4 ], [ $rows[0]->id, $rows[1]->id ] );
		self::assertSame( StudyKind::READING, $rows[1]->kind );
		self::assertSame( 30, $rows[1]->active_seconds );
	}

	public function test_rows_for_user_in_course_returns_empty_list_on_a_non_array_result(): void {
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( null );

		self::assertSame( [], $this->repo->rows_for_user_in_course( 7, 42 ) );
	}

	public function test_totals_for_user_sums_seconds_per_course_and_kind(): void {
		$this->wpdb->shouldReceive( 'get_results' )
			->once()
			->andReturn(
				[
					[
						'course_id' => '42',
						'kind'      => 'video',
						'total'     => '90',
					],
					[
						'course_id' => '42',
						'kind'      => 'reading',
						'total'     => '30',
					],
					[
						'course_id' => '43',
						'kind'      => 'reading',
						'total'     => '15',
					],
					[ 'course_id' => '44' ],
				]
			);

		$totals = $this->repo->totals_for_user( 7 );

		self::assertSame( 'SELECT course_id, kind, SUM(active_seconds) AS total FROM wp_vl_study_time WHERE user_id = 7 GROUP BY course_id, kind', $this->prepared );
		self::assertSame(
			[
				42 => [
					'video'   => 90,
					'reading' => 30,
				],
				43 => [ 'reading' => 15 ],
			],
			$totals
		);
	}

	public function test_totals_for_user_returns_empty_array_on_a_non_array_result(): void {
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( null );

		self::assertSame( [], $this->repo->totals_for_user( 7 ) );
	}
}
