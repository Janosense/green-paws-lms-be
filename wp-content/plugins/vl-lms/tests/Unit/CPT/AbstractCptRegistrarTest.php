<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\CPT;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\CPT\AbstractCptRegistrar;

final class AbstractCptRegistrarTest extends TestCase {

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

	public function test_register_calls_register_post_type_once_with_the_configured_slug(): void {
		$captured_slug = null;
		$captured_args = null;

		Functions\expect( 'register_post_type' )
			->once()
			->andReturnUsing(
				static function ( string $slug, array $args ) use ( &$captured_slug, &$captured_args ) {
					$captured_slug = $slug;
					$captured_args = $args;
					return null;
				}
			);
		Functions\when( 'register_post_meta' )->justReturn( true );

		$this->make_fixture()->register();

		self::assertSame( 'vl_fixture', $captured_slug );
		self::assertIsArray( $captured_args );
	}

	public function test_register_post_type_args_contain_the_headless_defaults(): void {
		$captured_args = null;

		Functions\when( 'register_post_type' )->alias(
			static function ( string $slug, array $args ) use ( &$captured_args ) {
				$captured_args = $args;
				return null;
			}
		);
		Functions\when( 'register_post_meta' )->justReturn( true );

		$this->make_fixture()->register();

		self::assertFalse( $captured_args['public'] );
		self::assertFalse( $captured_args['publicly_queryable'] );
		self::assertTrue( $captured_args['show_ui'] );
		self::assertFalse( $captured_args['show_in_nav_menus'] );
		self::assertFalse( $captured_args['show_in_rest'] );
		self::assertFalse( $captured_args['query_var'] );
		self::assertFalse( $captured_args['rewrite'] );
		self::assertFalse( $captured_args['has_archive'] );
		self::assertTrue( $captured_args['exclude_from_search'] );
		self::assertTrue( $captured_args['map_meta_cap'] );
		self::assertTrue( $captured_args['can_export'] );
		self::assertFalse( $captured_args['delete_with_user'] );
	}

	public function test_register_post_type_args_contain_labels_and_capability_type(): void {
		$captured_args = null;

		Functions\when( 'register_post_type' )->alias(
			static function ( string $slug, array $args ) use ( &$captured_args ) {
				$captured_args = $args;
				return null;
			}
		);
		Functions\when( 'register_post_meta' )->justReturn( true );

		$this->make_fixture()->register();

		self::assertIsArray( $captured_args['labels'] );
		self::assertArrayHasKey( 'name', $captured_args['labels'] );
		self::assertArrayHasKey( 'singular_name', $captured_args['labels'] );
		self::assertArrayHasKey( 'add_new_item', $captured_args['labels'] );
		self::assertArrayHasKey( 'edit_item', $captured_args['labels'] );

		self::assertSame( [ 'vl_fixture', 'vl_fixtures' ], $captured_args['capability_type'] );
		self::assertSame( [ 'title', 'editor' ], $captured_args['supports'] );
		self::assertSame( 'dashicons-star-empty', $captured_args['menu_icon'] );
		self::assertFalse( $captured_args['hierarchical'] );
		self::assertTrue( $captured_args['show_in_menu'] );
	}

	public function test_register_calls_register_post_meta_once_per_meta_field_with_post_type_slug(): void {
		$calls = [];

		Functions\when( 'register_post_type' )->justReturn( null );
		Functions\when( 'register_post_meta' )->alias(
			static function ( string $post_type, string $meta_key, array $args ) use ( &$calls ): bool {
				$calls[] = [ $post_type, $meta_key, $args ];
				return true;
			}
		);

		$this->make_fixture()->register();

		self::assertCount( 2, $calls );
		foreach ( $calls as $call ) {
			self::assertSame( 'vl_fixture', $call[0] );
			self::assertFalse( $call[2]['show_in_rest'] );
			self::assertTrue( $call[2]['single'] );
			self::assertIsCallable( $call[2]['sanitize_callback'] );
		}

		$meta_keys = array_map( static fn ( array $c ): string => $c[1], $calls );
		self::assertSame( [ '_vl_fixture_alpha', '_vl_fixture_beta' ], $meta_keys );
	}

	private function make_fixture(): AbstractCptRegistrar {
		return new class() extends AbstractCptRegistrar {
			protected function post_type(): string {
				return 'vl_fixture';
			}

			protected function singular_label(): string {
				return 'Fixture';
			}

			protected function plural_label(): string {
				return 'Fixtures';
			}

			protected function capability_type(): array {
				return [ 'vl_fixture', 'vl_fixtures' ];
			}

			protected function supports(): array {
				return [ 'title', 'editor' ];
			}

			protected function menu_icon(): ?string {
				return 'dashicons-star-empty';
			}

			protected function hierarchical(): bool {
				return false;
			}

			protected function show_in_menu(): bool {
				return true;
			}

			protected function meta_fields(): array {
				$noop = static fn ( mixed $v ): mixed => $v;
				$auth = static fn (): bool => true;
				return [
					'_vl_fixture_alpha' => [
						'type'              => 'string',
						'single'            => true,
						'default'           => '',
						'show_in_rest'      => false,
						'sanitize_callback' => $noop,
						'auth_callback'     => $auth,
						'description'       => 'Alpha.',
					],
					'_vl_fixture_beta'  => [
						'type'              => 'integer',
						'single'            => true,
						'default'           => 0,
						'show_in_rest'      => false,
						'sanitize_callback' => $noop,
						'auth_callback'     => $auth,
						'description'       => 'Beta.',
					],
				];
			}
		};
	}
}
