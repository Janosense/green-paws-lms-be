<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Admin\Analytics;

use Brain\Monkey;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Admin\Analytics\AnalyticsRollupService;

final class AnalyticsRollupServiceTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface */
	private $wpdb;

	/** @var list<string> */
	private array $get_results_queries = [];

	/** @var list<string> */
	private array $query_calls = [];

	/** @var array<string, list<array<string, mixed>>> */
	private array $results_by_marker = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->get_results_queries = [];
		$this->query_calls         = [];
		$this->results_by_marker   = [];

		$wpdb         = Mockery::mock();
		$wpdb->prefix = 'wp_';
		$wpdb->posts  = 'wp_posts';

		// `prepare` echoes the template with positional placeholders folded
		// into a deterministic string; tests assert against the substituted
		// query body via $get_results_queries / $query_calls below.
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( string $sql, ...$args ): string {
				$flat = [];
				foreach ( $args as $arg ) {
					if ( is_array( $arg ) ) {
						foreach ( $arg as $a ) {
							$flat[] = $a;
						}
					} else {
						$flat[] = $arg;
					}
				}
				$out = $sql;
				foreach ( $flat as $value ) {
					$replacement = is_int( $value ) ? (string) $value : "'" . (string) $value . "'";
					$out         = preg_replace( '/%[ds]/', $replacement, $out, 1 ) ?? $out;
				}
				return $out;
			}
		);

		$queries = &$this->get_results_queries;
		$markers = &$this->results_by_marker;
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			static function ( string $sql, $output = ARRAY_A ) use ( &$queries, &$markers ): array {
				unset( $output );
				$queries[] = $sql;
				foreach ( $markers as $needle => $rows ) {
					if ( false !== strpos( $sql, $needle ) ) {
						return $rows;
					}
				}
				return [];
			}
		);

		$qcalls = &$this->query_calls;
		$wpdb->shouldReceive( 'query' )->andReturnUsing(
			static function ( string $sql ) use ( &$qcalls ): int {
				$qcalls[] = $sql;
				return 1;
			}
		);

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test double for $wpdb.
		$GLOBALS['wpdb'] = $wpdb;
		$this->wpdb      = $wpdb;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_rollup_calls_wpdb_query_for_enrollments(): void {
		$service = new AnalyticsRollupService();

		$service->rollup( '2026-05-01' );

		$found = false;
		foreach ( $this->get_results_queries as $sql ) {
			if ( false !== strpos( $sql, 'wp_vl_enrollments' )
				&& false !== strpos( $sql, '2026-05-01' )
				&& false === strpos( $sql, 'completed' )
			) {
				$found = true;
				break;
			}
		}
		self::assertTrue( $found, 'Expected an enrollments-table query containing the date.' );
	}

	public function test_rollup_calls_wpdb_query_for_lesson_views(): void {
		$service = new AnalyticsRollupService();

		$service->rollup( '2026-05-01' );

		$found = false;
		foreach ( $this->get_results_queries as $sql ) {
			if ( false !== strpos( $sql, 'wp_vl_lesson_views' ) ) {
				$found = true;
				break;
			}
		}
		self::assertTrue( $found, 'Expected a lesson-views-table query.' );
	}

	public function test_rollup_calls_insert_for_each_course(): void {
		// Step 1 returns one course; steps 2 & 3 return nothing.
		$this->results_by_marker['wp_vl_enrollments WHERE DATE(enrolled_at)'] = [
			[
				'course_id' => '101',
				'cnt'       => '5',
			],
		];

		$service = new AnalyticsRollupService();

		$service->rollup( '2026-05-01' );

		self::assertCount( 1, $this->query_calls );
		self::assertStringContainsString( 'INSERT INTO wp_vl_user_activity_daily', $this->query_calls[0] );
		self::assertStringContainsString( 'ON DUPLICATE KEY UPDATE', $this->query_calls[0] );
	}
}
