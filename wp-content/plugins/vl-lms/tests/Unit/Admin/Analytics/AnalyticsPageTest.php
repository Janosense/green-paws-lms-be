<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Admin\Analytics;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * The page's first tests, added with its extension point (study-time
 * Sprint 2 Step 2). They cover what that hook promises — it fires once on
 * both branches of `render()` and changes no markup — not the page's chart
 * internals.
 */
final class AnalyticsPageTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias( static fn ( $value ): string => (string) json_encode( $value ) );
		Functions\when( 'number_format_i18n' )->alias( static fn ( $n, $d = 0 ): string => number_format( (float) $n, (int) $d ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private static function one_day(): array {
		return [
			[
				'activity_date'   => '2026-09-15',
				'new_enrollments' => 3,
				'active_users'    => 12,
				'completions'     => 1,
			],
		];
	}

	private function render( TestableAnalyticsPage $page ): string {
		ob_start();
		$page->render();
		return (string) ob_get_clean();
	}

	public function test_it_offers_the_page_to_feature_sections(): void {
		Actions\expectDone( 'vl_lms_admin_analytics_sections' )->once()->withNoArgs();

		$output = $this->render( new TestableAnalyticsPage( self::one_day() ) );

		self::assertStringContainsString( 'Нові записи', $output );
		self::assertStringContainsString( 'Завершення', $output );
	}

	public function test_it_offers_the_page_even_before_the_nightly_rollup_has_run(): void {
		// The empty branch is the state of every fresh environment until the
		// analytics cron first fires. A feature section reads its own data
		// and must not wait a day for it.
		Actions\expectDone( 'vl_lms_admin_analytics_sections' )->once()->withNoArgs();

		$output = $this->render( new TestableAnalyticsPage() );

		self::assertStringContainsString( 'Ще немає аналітичних даних', $output );
	}

	public function test_the_hook_carries_no_arguments_a_listener_could_come_to_depend_on(): void {
		$seen = null;
		Actions\expectDone( 'vl_lms_admin_analytics_sections' )
			->once()
			->whenHappen(
				static function ( ...$args ) use ( &$seen ): void {
					$seen = $args;
				}
			);

		$this->render( new TestableAnalyticsPage( self::one_day() ) );

		self::assertSame( [], $seen );
	}

	public function test_the_hook_adds_no_markup_of_its_own(): void {
		// Nothing listens yet: the page must close its wrapper exactly where
		// it did before.
		$output = $this->render( new TestableAnalyticsPage( self::one_day() ) );

		self::assertStringEndsWith( '</table></div>', trim( $output ) );
	}
}
