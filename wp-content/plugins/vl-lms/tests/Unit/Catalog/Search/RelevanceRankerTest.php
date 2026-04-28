<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Catalog\Search;

use Brain\Monkey;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Catalog\Search\RelevanceRanker;
use WP_Query;

final class RelevanceRankerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Minimal $wpdb stub: enough to verify the prepared SQL fragment
		// returned by the orderby filter. `prepare` returns the format
		// string with the placeholder swapped in; `esc_like` is identity.
		$wpdb        = Mockery::mock();
		$wpdb->posts = 'wp_posts';
		$wpdb->shouldReceive( 'esc_like' )->andReturnUsing(
			static fn ( string $s ): string => $s
		);
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( string $sql, mixed $arg ): string {
				return str_replace( '%s', "'" . (string) $arg . "'", $sql );
			}
		);
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test-time fixture for isolated unit test of an SQL fragment.
		$GLOBALS['wpdb'] = $wpdb;
	}

	protected function tearDown(): void {
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Cleanup of the test fixture.
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_filter_orderby_modifies_only_the_bound_query(): void {
		$ranker = new RelevanceRanker();

		$bound_query = $this->mockQuery();
		$other_query = $this->mockQuery();

		$ranker->bind( $bound_query, 'cardiology' );

		$default_orderby = 'wp_posts.post_date DESC';

		$bound_result = $ranker->filter_orderby( $default_orderby, $bound_query );
		self::assertNotSame( $default_orderby, $bound_result );
		self::assertStringContainsString( 'CASE WHEN', $bound_result );
		self::assertStringContainsString( 'cardiology', $bound_result );

		$other_result = $ranker->filter_orderby( $default_orderby, $other_query );
		self::assertSame( $default_orderby, $other_result, 'Unbound queries must pass through unchanged.' );

		$ranker->release();
	}

	public function test_release_clears_binding(): void {
		$ranker = new RelevanceRanker();
		$query  = $this->mockQuery();

		$ranker->bind( $query, 'cardiology' );
		$ranker->release();

		// Post-release, the orderby filter is a no-op even for the
		// previously bound query.
		$default_orderby = 'wp_posts.post_date DESC';
		self::assertSame(
			$default_orderby,
			$ranker->filter_orderby( $default_orderby, $query )
		);
	}

	public function test_release_after_run_clears_binding_for_bound_query(): void {
		$ranker = new RelevanceRanker();
		$query  = $this->mockQuery();

		$ranker->bind( $query, 'cardiology' );

		$posts = $ranker->release_after_run( [], $query );
		self::assertSame( [], $posts, 'Posts should pass through unchanged.' );

		$default_orderby = 'wp_posts.post_date DESC';
		self::assertSame(
			$default_orderby,
			$ranker->filter_orderby( $default_orderby, $query )
		);
	}

	public function test_release_after_run_does_not_clear_for_other_queries(): void {
		$ranker = new RelevanceRanker();

		$bound_query = $this->mockQuery();
		$other_query = $this->mockQuery();
		$ranker->bind( $bound_query, 'cardiology' );

		$ranker->release_after_run( [], $other_query );

		$default_orderby = 'wp_posts.post_date DESC';
		$result          = $ranker->filter_orderby( $default_orderby, $bound_query );
		self::assertNotSame( $default_orderby, $result );

		$ranker->release();
	}

	public function test_double_release_is_safe(): void {
		$ranker = new RelevanceRanker();
		$query  = $this->mockQuery();

		$ranker->bind( $query, 'cardiology' );
		$ranker->release();
		$ranker->release(); // Should not throw.

		self::assertTrue( true );
	}

	private function mockQuery(): WP_Query {
		$query = Mockery::mock( 'WP_Query' );
		assert( $query instanceof WP_Query );
		return $query;
	}
}
