<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Taxonomy;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Taxonomy\AbstractTaxonomyRegistrar;

final class AbstractTaxonomyRegistrarTest extends TestCase {

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

	public function test_register_calls_register_taxonomy_once_with_configured_slug_and_object_types(): void {
		$captured_slug         = null;
		$captured_object_types = null;
		$captured_args         = null;

		Functions\expect( 'register_taxonomy' )
			->once()
			->andReturnUsing(
				static function (
					string $slug,
					array $object_types,
					array $args
				) use (
					&$captured_slug,
					&$captured_object_types,
					&$captured_args
				) {
					$captured_slug         = $slug;
					$captured_object_types = $object_types;
					$captured_args         = $args;
					return null;
				}
			);

		$this->make_fixture()->register();

		self::assertSame( 'vl_fixture', $captured_slug );
		self::assertSame( [ 'vl_course', 'vl_webinar' ], $captured_object_types );
		self::assertIsArray( $captured_args );
	}

	public function test_register_taxonomy_args_contain_the_headless_defaults(): void {
		$captured_args = null;

		Functions\when( 'register_taxonomy' )->alias(
			static function ( string $slug, array $object_types, array $args ) use ( &$captured_args ) {
				$captured_args = $args;
				return null;
			}
		);

		$this->make_fixture()->register();

		self::assertFalse( $captured_args['public'] );
		self::assertFalse( $captured_args['publicly_queryable'] );
		self::assertTrue( $captured_args['show_ui'] );
		self::assertFalse( $captured_args['show_in_nav_menus'] );
		self::assertFalse( $captured_args['show_in_rest'] );
		self::assertFalse( $captured_args['query_var'] );
		self::assertFalse( $captured_args['rewrite'] );
		self::assertTrue( $captured_args['show_admin_column'] );
		self::assertFalse( $captured_args['hierarchical'] );
	}

	public function test_register_taxonomy_args_contain_labels_with_required_keys(): void {
		$captured_args = null;

		Functions\when( 'register_taxonomy' )->alias(
			static function ( string $slug, array $object_types, array $args ) use ( &$captured_args ) {
				$captured_args = $args;
				return null;
			}
		);

		$this->make_fixture()->register();

		self::assertIsArray( $captured_args['labels'] );
		self::assertArrayHasKey( 'name', $captured_args['labels'] );
		self::assertArrayHasKey( 'singular_name', $captured_args['labels'] );
		self::assertArrayHasKey( 'add_new_item', $captured_args['labels'] );
		self::assertArrayHasKey( 'edit_item', $captured_args['labels'] );
	}

	public function test_register_taxonomy_args_omit_capabilities_when_override_is_null(): void {
		$captured_args = null;

		Functions\when( 'register_taxonomy' )->alias(
			static function ( string $slug, array $object_types, array $args ) use ( &$captured_args ) {
				$captured_args = $args;
				return null;
			}
		);

		$this->make_fixture( null )->register();

		self::assertIsArray( $captured_args );
		self::assertArrayNotHasKey( 'capabilities', $captured_args );
	}

	public function test_register_taxonomy_args_include_capabilities_when_override_is_provided(): void {
		$captured_args = null;
		$override      = [
			'manage_terms' => 'manage_options',
			'edit_terms'   => 'manage_options',
			'delete_terms' => 'manage_options',
			'assign_terms' => 'edit_posts',
		];

		Functions\when( 'register_taxonomy' )->alias(
			static function ( string $slug, array $object_types, array $args ) use ( &$captured_args ) {
				$captured_args = $args;
				return null;
			}
		);

		$this->make_fixture( $override )->register();

		self::assertSame( $override, $captured_args['capabilities'] );
	}

	/**
	 * @param array{manage_terms: string, edit_terms: string, delete_terms: string, assign_terms: string}|null $capabilities
	 */
	private function make_fixture( ?array $capabilities = null ): AbstractTaxonomyRegistrar {
		return new class( $capabilities ) extends AbstractTaxonomyRegistrar {

			/**
			 * @param array{manage_terms: string, edit_terms: string, delete_terms: string, assign_terms: string}|null $capabilities
			 */
			public function __construct( private ?array $capabilities = null ) {
			}

			protected function taxonomy(): string {
				return 'vl_fixture';
			}

			protected function object_types(): array {
				return [ 'vl_course', 'vl_webinar' ];
			}

			protected function singular_label(): string {
				return 'Fixture';
			}

			protected function plural_label(): string {
				return 'Fixtures';
			}

			protected function hierarchical(): bool {
				return false;
			}

			protected function capabilities(): ?array {
				return $this->capabilities;
			}
		};
	}
}
