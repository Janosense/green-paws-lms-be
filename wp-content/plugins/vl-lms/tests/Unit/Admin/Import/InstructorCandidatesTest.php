<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Admin\Import;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use stdClass;
use VL\LMS\Admin\Import\InstructorCandidates;
use WP_User;

final class InstructorCandidatesTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const ADMIN_ID = 1;

	private const CANDIDATES = [
		[
			'id'           => 12,
			'display_name' => 'Іваненко Олена',
			'user_login'   => 'olena',
		],
		[
			'id'           => 14,
			'display_name' => 'Петренко Іван',
			'user_login'   => 'ivan',
		],
		[
			'id'           => self::ADMIN_ID,
			'display_name' => 'Адміністратор',
			'user_login'   => 'admin',
		],
	];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_lists_the_instructors_by_name_and_then_the_current_user(): void {
		$queries = [];
		Functions\when( 'get_users' )->alias(
			static function ( array $args ) use ( &$queries ): array {
				$queries[] = $args;

				return [ self::row( '12', 'Іваненко Олена', 'olena' ), self::row( '14', 'Петренко Іван', 'ivan' ) ];
			}
		);
		Functions\when( 'get_userdata' )->alias( static fn ( int $id ): WP_User => self::user( $id, 'Адміністратор', 'admin' ) );

		$candidates = ( new InstructorCandidates() )->for_user( self::ADMIN_ID );

		self::assertSame( self::CANDIDATES, $candidates );
		self::assertSame(
			[
				[
					'role'    => 'instructor',
					'orderby' => 'display_name',
					'order'   => 'ASC',
					'fields'  => [ 'ID', 'display_name', 'user_login' ],
				],
			],
			$queries
		);
	}

	public function test_a_current_user_who_is_an_instructor_is_listed_once(): void {
		Functions\when( 'get_users' )->justReturn( [ self::row( '12', 'Іваненко Олена', 'olena' ), self::row( '1', 'Адміністратор', 'admin' ) ] );
		Functions\expect( 'get_userdata' )->never();

		$candidates = ( new InstructorCandidates() )->for_user( self::ADMIN_ID );

		self::assertSame( [ 12, self::ADMIN_ID ], array_column( $candidates, 'id' ) );
	}

	/**
	 * @return array<string, array{0: string, 1: int}>
	 */
	public static function authors(): array {
		return [
			'exact match'          => [ 'Петренко Іван', 14 ],
			'the first instructor' => [ 'Іваненко Олена', 12 ],
			'the admin by name'    => [ 'Адміністратор', self::ADMIN_ID ],
			'no match'             => [ 'Коваль Марія', self::ADMIN_ID ],
			'different case'       => [ 'петренко іван', self::ADMIN_ID ],
			'surrounding spaces'   => [ ' Петренко Іван ', self::ADMIN_ID ],
			'an empty author'      => [ '', self::ADMIN_ID ],
		];
	}

	/**
	 * @dataProvider authors
	 */
	public function test_preselects_the_candidate_whose_display_name_is_the_author( string $author, int $expected ): void {
		self::assertSame( $expected, InstructorCandidates::preselect( self::CANDIDATES, $author, self::ADMIN_ID ) );
	}

	public function test_of_two_candidates_with_the_author_s_name_the_first_is_preselected(): void {
		$candidates   = self::CANDIDATES;
		$candidates[] = [
			'id'           => 20,
			'display_name' => 'Петренко Іван',
			'user_login'   => 'ivan2',
		];

		self::assertSame( 14, InstructorCandidates::preselect( $candidates, 'Петренко Іван', self::ADMIN_ID ) );
	}

	/**
	 * A row as `get_users()` returns it for a `fields` list: a plain object with string values.
	 */
	private static function row( string $id, string $display_name, string $user_login ): stdClass {
		$row               = new stdClass();
		$row->ID           = $id;
		$row->display_name = $display_name;
		$row->user_login   = $user_login;

		return $row;
	}

	private static function user( int $id, string $display_name, string $user_login ): WP_User {
		$user               = new WP_User();
		$user->ID           = $id;
		$user->display_name = $display_name;
		$user->user_login   = $user_login;

		return $user;
	}
}
