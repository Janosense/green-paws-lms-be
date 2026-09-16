<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Admin\StudyTime;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Admin\StudyTime\InstructorDashboardColumn;
use VL\LMS\StudyTime\Reports\StudyTimeReportQuery;

/**
 * The «Час (середній)» column: one header, one cell per row — the balance
 * `core`'s hand-rendered table depends on — and one query per course however
 * many times a row asks.
 */
final class InstructorDashboardColumnTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'number_format_i18n' )->alias( static fn ( $n, $d = 0 ): string => number_format( (float) $n, (int) $d, ',', ' ' ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param array<int, int> $averages
	 */
	private function column( array $averages, ?Mockery\MockInterface &$query = null ): InstructorDashboardColumn {
		$query = Mockery::mock( StudyTimeReportQuery::class );
		$query->shouldReceive( 'avg_total_by_course' )
			->andReturnUsing(
				static function ( array $ids ) use ( $averages ): array {
					$out = [];
					foreach ( $ids as $id ) {
						if ( isset( $averages[ $id ] ) ) {
							$out[ $id ] = $averages[ $id ];
						}
					}
					return $out;
				}
			);

		return new InstructorDashboardColumn( $query );
	}

	private function capture( callable $render ): string {
		ob_start();
		$render();
		return (string) ob_get_clean();
	}

	public function test_the_header_is_exactly_one_cell(): void {
		$output = $this->capture( fn () => $this->column( [] )->header() );

		self::assertSame( '<th>Час (середній)</th>', $output );
		self::assertSame( 1, substr_count( $output, '<th' ) );
	}

	public function test_a_row_gets_exactly_one_cell_with_the_formatted_average(): void {
		$column = $this->column( [ 101 => 4716 ] );

		$output = $this->capture( static fn () => $column->cell( 101 ) );

		self::assertSame( '<td>1 год 18 хв</td>', $output );
		self::assertSame( 1, substr_count( $output, '<td' ) );
	}

	public function test_a_course_nobody_has_studied_reads_as_a_dash(): void {
		// Absent from the map — the query omits courses with no time.
		$column = $this->column( [] );

		self::assertSame( '<td>—</td>', $this->capture( static fn () => $column->cell( 999 ) ) );
	}

	public function test_an_explicit_zero_also_reads_as_a_dash(): void {
		$column = $this->column( [ 101 => 0 ] );

		self::assertSame( '<td>—</td>', $this->capture( static fn () => $column->cell( 101 ) ) );
	}

	public function test_each_course_is_asked_for_once_however_many_rows_ask(): void {
		$query  = null;
		$column = $this->column(
			[
				101 => 600,
				202 => 1200,
			],
			$query
		);

		$this->capture(
			static function () use ( $column ): void {
				$column->cell( 101 );
				$column->cell( 101 );
				$column->cell( 202 );
				$column->cell( 101 );
			}
		);

		$query->shouldHaveReceived( 'avg_total_by_course' )->twice();
	}

	public function test_a_course_without_time_is_not_asked_for_again_either(): void {
		// The miss is remembered too, or a long list of unstudied courses
		// would re-query on every row.
		$query  = null;
		$column = $this->column( [], $query );

		$this->capture(
			static function () use ( $column ): void {
				$column->cell( 999 );
				$column->cell( 999 );
			}
		);

		$query->shouldHaveReceived( 'avg_total_by_course' )->once();
	}
}
