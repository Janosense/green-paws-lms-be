<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Roles;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Roles\CapabilitiesMap;
use VL\LMS\Roles\RolesInstaller;

final class RolesInstallerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg( 1 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_install_calls_add_role_three_times_when_no_roles_exist(): void {
		Functions\when( 'get_role' )->justReturn( null );
		Functions\expect( 'add_role' )->times( 3 );

		RolesInstaller::install();
	}

	public function test_install_existing_role_adds_only_missing_caps(): void {
		$granted    = [];
		$instructor = Mockery::mock( 'WP_Role' );
		$instructor->shouldReceive( 'has_cap' )
			->andReturnUsing( static fn ( string $cap ): bool => 'read' === $cap );
		$instructor->shouldReceive( 'add_cap' )
			->andReturnUsing(
				static function ( string $cap ) use ( &$granted ): void {
					$granted[] = $cap;
				}
			);

		$moderator = Mockery::mock( 'WP_Role' );
		$moderator->shouldReceive( 'has_cap' )->andReturnTrue();
		$moderator->shouldReceive( 'add_cap' )->never();

		$student = Mockery::mock( 'WP_Role' );
		$student->shouldReceive( 'has_cap' )->andReturnTrue();
		$student->shouldReceive( 'add_cap' )->never();

		$admin = Mockery::mock( 'WP_Role' );
		$admin->shouldReceive( 'has_cap' )->andReturnTrue();
		$admin->shouldReceive( 'add_cap' )->never();

		Functions\when( 'get_role' )->alias(
			static function ( string $slug ) use ( $instructor, $moderator, $student, $admin ) {
				return match ( $slug ) {
					'instructor'    => $instructor,
					'moderator'     => $moderator,
					'student'       => $student,
					'administrator' => $admin,
					default         => null,
				};
			}
		);
		Functions\expect( 'add_role' )->never();

		RolesInstaller::install();

		self::assertNotContains( 'read', $granted, 'Caps already held must not be re-added.' );
		self::assertContains( 'upload_files', $granted );
		self::assertContains( 'vl_enroll_in_course', $granted );
	}

	public function test_install_grants_all_plugin_caps_to_administrator(): void {
		$granted = [];
		$admin   = Mockery::mock( 'WP_Role' );
		$admin->shouldReceive( 'has_cap' )->andReturnFalse();
		$admin->shouldReceive( 'add_cap' )
			->andReturnUsing(
				static function ( string $cap ) use ( &$granted ): void {
					$granted[] = $cap;
				}
			);

		Functions\when( 'get_role' )->alias(
			static fn ( string $slug ) => 'administrator' === $slug ? $admin : null
		);
		Functions\when( 'add_role' )->justReturn( null );

		RolesInstaller::install();

		$expected = array_merge( CapabilitiesMap::all_cpt_caps(), CapabilitiesMap::domain_caps() );
		foreach ( $expected as $cap ) {
			self::assertContains( $cap, $granted, sprintf( 'Administrator missing cap: %s', $cap ) );
		}
	}

	public function test_install_is_idempotent_on_second_invocation(): void {
		$roles = [];
		foreach ( [ 'instructor', 'moderator', 'student', 'administrator' ] as $slug ) {
			$role = Mockery::mock( 'WP_Role' );
			$role->shouldReceive( 'has_cap' )->andReturnTrue();
			$role->shouldReceive( 'add_cap' )->never();
			$roles[ $slug ] = $role;
		}

		Functions\when( 'get_role' )->alias(
			static fn ( string $slug ) => $roles[ $slug ] ?? null
		);
		Functions\expect( 'add_role' )->never();

		RolesInstaller::install();
		RolesInstaller::install();
	}

	public function test_uninstall_calls_remove_role_for_each_custom_role(): void {
		$removed = [];
		Functions\when( 'get_users' )->justReturn( [] );
		Functions\when( 'get_role' )->justReturn( null );
		Functions\when( 'remove_role' )->alias(
			static function ( string $slug ) use ( &$removed ): void {
				$removed[] = $slug;
			}
		);

		RolesInstaller::uninstall();

		self::assertSame( [ 'instructor', 'moderator', 'student' ], $removed );
	}

	public function test_uninstall_strips_plugin_caps_from_administrator_but_leaves_core_caps(): void {
		$stripped = [];
		$admin    = Mockery::mock( 'WP_Role' );
		$admin->shouldReceive( 'remove_cap' )
			->andReturnUsing(
				static function ( string $cap ) use ( &$stripped ): void {
					$stripped[] = $cap;
				}
			);

		Functions\when( 'get_users' )->justReturn( [] );
		Functions\when( 'remove_role' )->justReturn( null );
		Functions\when( 'get_role' )->alias(
			static fn ( string $slug ) => 'administrator' === $slug ? $admin : null
		);

		RolesInstaller::uninstall();

		foreach ( CapabilitiesMap::all_cpt_caps() as $cap ) {
			self::assertContains( $cap, $stripped );
		}
		foreach ( CapabilitiesMap::domain_caps() as $cap ) {
			self::assertContains( $cap, $stripped );
		}
		self::assertNotContains( 'read', $stripped );
		self::assertNotContains( 'upload_files', $stripped );
	}

	public function test_uninstall_reassigns_user_to_subscriber_when_left_with_no_roles(): void {
		$user        = Mockery::mock();
		$user->roles = [ 'instructor' ];
		$user->shouldReceive( 'remove_role' )
			->once()
			->with( 'instructor' )
			->andReturnUsing(
				static function () use ( $user ): void {
					$user->roles = [];
				}
			);
		$user->shouldReceive( 'add_role' )->once()->with( 'subscriber' );

		Functions\when( 'get_users' )->alias(
			static fn ( array $args ): array => 'instructor' === $args['role'] ? [ $user ] : []
		);
		Functions\when( 'remove_role' )->justReturn( null );
		Functions\when( 'get_role' )->justReturn( null );

		RolesInstaller::uninstall();
	}

	public function test_uninstall_does_not_reassign_user_with_remaining_roles(): void {
		$user        = Mockery::mock();
		$user->roles = [ 'instructor', 'editor' ];
		$user->shouldReceive( 'remove_role' )
			->once()
			->with( 'instructor' )
			->andReturnUsing(
				static function () use ( $user ): void {
					$user->roles = [ 'editor' ];
				}
			);
		$user->shouldReceive( 'add_role' )->never();

		Functions\when( 'get_users' )->alias(
			static fn ( array $args ): array => 'instructor' === $args['role'] ? [ $user ] : []
		);
		Functions\when( 'remove_role' )->justReturn( null );
		Functions\when( 'get_role' )->justReturn( null );

		RolesInstaller::uninstall();
	}
}
