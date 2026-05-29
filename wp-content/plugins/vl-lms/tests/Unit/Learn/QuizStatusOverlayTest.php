<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Learn;

use PHPUnit\Framework\TestCase;
use VL\LMS\Learn\QuizStatusOverlay;

final class QuizStatusOverlayTest extends TestCase {

	/**
	 * @param array{passed: bool, in_progress: bool, submitted_count: int, best_pct: float|null} $row
	 *
	 * @dataProvider statusProvider
	 */
	public function test_status_derivation( array $row, string $expected ): void {
		$overlay = QuizStatusOverlay::fromMap( [ 7 => $row ] );

		self::assertSame( $expected, $overlay->status( 7 ) );
	}

	/**
	 * @return array<string, array{0: array{passed: bool, in_progress: bool, submitted_count: int, best_pct: float|null}, 1: string}>
	 */
	public static function statusProvider(): array {
		return [
			'passed wins over everything'     => [
				[
					'passed'          => true,
					'in_progress'     => true,
					'submitted_count' => 3,
					'best_pct'        => 90.0,
				],
				'passed',
			],
			'in_progress when not yet passed' => [
				[
					'passed'          => false,
					'in_progress'     => true,
					'submitted_count' => 1,
					'best_pct'        => 40.0,
				],
				'in_progress',
			],
			'failed when only spent attempts' => [
				[
					'passed'          => false,
					'in_progress'     => false,
					'submitted_count' => 2,
					'best_pct'        => 50.0,
				],
				'failed',
			],
			'not_started when no submissions and none open' => [
				[
					'passed'          => false,
					'in_progress'     => false,
					'submitted_count' => 0,
					'best_pct'        => null,
				],
				'not_started',
			],
		];
	}

	public function test_status_is_not_started_for_unknown_quiz(): void {
		$overlay = QuizStatusOverlay::fromMap( [] );

		self::assertSame( 'not_started', $overlay->status( 999 ) );
		self::assertNull( $overlay->best_score_pct( 999 ) );
	}

	public function test_best_score_pct_is_surfaced(): void {
		$overlay = QuizStatusOverlay::fromMap(
			[
				7 => [
					'passed'          => true,
					'in_progress'     => false,
					'submitted_count' => 1,
					'best_pct'        => 83.5,
				],
			]
		);

		self::assertSame( 83.5, $overlay->best_score_pct( 7 ) );
	}
}
