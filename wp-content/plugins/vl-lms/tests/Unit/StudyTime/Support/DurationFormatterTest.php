<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\StudyTime\Support;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use VL\LMS\StudyTime\Support\DurationFormatter;

/**
 * The formatter is the only place a study-time figure becomes a string, so
 * the table below is the contract every surface of Sprint 2 renders.
 */
final class DurationFormatterTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'number_format_i18n' )->alias(
			static fn ( $number, $decimals = 0 ): string => number_format( (float) $number, (int) $decimals, ',', ' ' )
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @return array<string, array{0: int, 1: string}>
	 */
	public static function durations(): array {
		return [
			'no signal at all renders zero minutes'   => [ 0, '0 хв' ],
			'a negative figure cannot happen, and is reported as zero rather than as a minus' => [ -30, '0 хв' ],
			'under a minute is never rounded to zero' => [ 1, '< 1 хв' ],
			'just under a minute'                     => [ 59, '< 1 хв' ],
			'exactly a minute'                        => [ 60, '1 хв' ],
			'seconds past a minute are dropped'       => [ 119, '1 хв' ],
			'just under an hour stays in minutes'     => [ 3599, '59 хв' ],
			'exactly an hour shows a zero-padded zero' => [ 3600, '1 год 00 хв' ],
			'one past the hour keeps two digits'      => [ 3660, '1 год 01 хв' ],
			'the customer-facing example'             => [ 43_500, '12 год 05 хв' ],
			'a four-digit hour count is grouped'      => [ 3600 * 1234 + 120, '1 234 год 02 хв' ],
		];
	}

	/**
	 * @dataProvider durations
	 */
	public function test_it_renders_seconds_the_way_every_study_time_surface_shows_them( int $seconds, string $expected ): void {
		$this->assertSame( $expected, DurationFormatter::uk( $seconds ) );
	}

	public function test_it_never_returns_an_em_dash_for_an_empty_figure(): void {
		// «Панель інструктора» renders «—» for a course nobody studied; that
		// is the renderer's decision, and the formatter must not pre-empt it
		// with punctuation a table cell would have to interpret.
		$this->assertStringNotContainsString( '—', DurationFormatter::uk( 0 ) );
	}
}
