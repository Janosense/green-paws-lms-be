<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Access;

use PHPUnit\Framework\TestCase;
use VL\LMS\Access\NullCoInstructorLookup;

final class NullCoInstructorLookupTest extends TestCase {

	public function test_returns_false_for_arbitrary_user_and_post(): void {
		$lookup = new NullCoInstructorLookup();

		self::assertFalse( $lookup->is_co_instructor( 1, 42 ) );
	}

	public function test_returns_false_for_zero_ids(): void {
		$lookup = new NullCoInstructorLookup();

		self::assertFalse( $lookup->is_co_instructor( 0, 0 ) );
	}

	/**
	 * @dataProvider representative_pairs
	 */
	public function test_returns_false_for_every_representative_pair( int $user_id, int $post_id ): void {
		$lookup = new NullCoInstructorLookup();

		self::assertFalse( $lookup->is_co_instructor( $user_id, $post_id ) );
	}

	/**
	 * @return array<string, array{int, int}>
	 */
	public static function representative_pairs(): array {
		return [
			'small positive ids' => [ 1, 1 ],
			'unrelated ids'      => [ 17, 99 ],
			'large ids'          => [ 100_000, 250_000 ],
			'negative ids'       => [ -1, -1 ],
		];
	}
}
