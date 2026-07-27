<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Admin\Students;

use VL\LMS\Admin\Students\StudentsListTable;
use WP_User;

/**
 * Test double that swaps the `WP_User_Query` round-trip for a captured
 * args array + fixed result, so PHPUnit can assert what query the table
 * built without booting WordPress.
 *
 * The repositories are still the in-memory fixtures, so the batched joins
 * `prepare_items()` performs (enrollments, memberships, quiz attempts) run
 * for real against seeded rows.
 */
final class TestableStudentsListTable extends StudentsListTable {

	/** @var array<string, mixed>|null Captured args from the most-recent `fetch_users()` call. */
	public ?array $captured_args = null;

	/** @var list<WP_User> */
	public array $fake_users = [];

	public int $fake_total = 0;

	/**
	 * @param array<string, mixed> $args
	 * @return array{users: list<WP_User>, total: int}
	 */
	protected function fetch_users( array $args ): array {
		$this->captured_args = $args;
		return [
			'users' => $this->fake_users,
			'total' => $this->fake_total,
		];
	}
}
