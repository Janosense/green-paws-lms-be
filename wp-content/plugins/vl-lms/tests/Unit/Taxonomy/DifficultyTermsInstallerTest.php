<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Taxonomy;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Taxonomy\DifficultyTaxonomy;
use VL\LMS\Taxonomy\DifficultyTermsInstaller;

final class DifficultyTermsInstallerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'is_wp_error' )->alias(
			static fn ( $value ): bool => $value instanceof \WP_Error
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_install_returns_early_when_taxonomy_is_not_registered(): void {
		Functions\when( 'taxonomy_exists' )->justReturn( false );
		Functions\expect( 'wp_insert_term' )->never();
		Functions\expect( 'term_exists' )->never();

		DifficultyTermsInstaller::install();
	}

	public function test_install_inserts_one_term_per_slug_when_none_exist(): void {
		Functions\when( 'taxonomy_exists' )->justReturn( true );
		Functions\when( 'term_exists' )->justReturn( null );

		$inserted_slugs = [];
		Functions\expect( 'wp_insert_term' )
			->times( count( DifficultyTaxonomy::DEFAULT_TERMS ) )
			->andReturnUsing(
				static function ( string $name, string $taxonomy, array $args ) use ( &$inserted_slugs ): array {
					$inserted_slugs[] = $args['slug'];
					return [
						'term_id'          => count( $inserted_slugs ),
						'term_taxonomy_id' => count( $inserted_slugs ),
					];
				}
			);

		DifficultyTermsInstaller::install();

		self::assertSame(
			array_keys( DifficultyTaxonomy::DEFAULT_TERMS ),
			$inserted_slugs
		);
	}

	public function test_install_skips_terms_that_already_exist(): void {
		Functions\when( 'taxonomy_exists' )->justReturn( true );
		Functions\when( 'term_exists' )->alias(
			static function ( string $slug ): ?array {
				return 'basic' === $slug ? [ 'term_id' => 1 ] : null;
			}
		);

		$inserted_slugs = [];
		Functions\when( 'wp_insert_term' )->alias(
			static function ( string $name, string $taxonomy, array $args ) use ( &$inserted_slugs ): array {
				$inserted_slugs[] = $args['slug'];
				return [
					'term_id'          => 10,
					'term_taxonomy_id' => 10,
				];
			}
		);

		DifficultyTermsInstaller::install();

		self::assertNotContains( 'basic', $inserted_slugs );
		self::assertContains( 'advanced', $inserted_slugs );
		self::assertContains( 'expert', $inserted_slugs );
	}

	public function test_install_passes_slug_in_args(): void {
		Functions\when( 'taxonomy_exists' )->justReturn( true );
		Functions\when( 'term_exists' )->justReturn( null );

		$captured_calls = [];
		Functions\when( 'wp_insert_term' )->alias(
			static function ( string $name, string $taxonomy, array $args ) use ( &$captured_calls ): array {
				$captured_calls[] = [
					'name'     => $name,
					'taxonomy' => $taxonomy,
					'args'     => $args,
				];
				return [
					'term_id'          => 1,
					'term_taxonomy_id' => 1,
				];
			}
		);

		DifficultyTermsInstaller::install();

		foreach ( $captured_calls as $call ) {
			self::assertSame( 'vl_difficulty', $call['taxonomy'] );
			self::assertArrayHasKey( 'slug', $call['args'] );
			self::assertArrayHasKey( $call['args']['slug'], DifficultyTaxonomy::DEFAULT_TERMS );
		}
	}

	public function test_install_does_not_throw_when_wp_insert_term_returns_error(): void {
		Functions\when( 'taxonomy_exists' )->justReturn( true );
		Functions\when( 'term_exists' )->justReturn( null );

		$error = Mockery::mock( 'WP_Error' );
		Functions\when( 'wp_insert_term' )->justReturn( $error );

		DifficultyTermsInstaller::install();

		// Reaching this assertion means no exception bubbled up.
		self::assertTrue( true );
	}

	public function test_install_is_idempotent_on_repeated_invocation(): void {
		Functions\when( 'taxonomy_exists' )->justReturn( true );

		$inserted = 0;
		Functions\when( 'wp_insert_term' )->alias(
			static function () use ( &$inserted ): array {
				++$inserted;
				return [
					'term_id'          => $inserted,
					'term_taxonomy_id' => $inserted,
				];
			}
		);

		$existing_slugs = [];
		Functions\when( 'term_exists' )->alias(
			static function ( string $slug ) use ( &$existing_slugs ): ?array {
				if ( in_array( $slug, $existing_slugs, true ) ) {
					return [ 'term_id' => 1 ];
				}
				$existing_slugs[] = $slug;
				return null;
			}
		);

		DifficultyTermsInstaller::install();
		$after_first = $inserted;

		DifficultyTermsInstaller::install();
		$after_second = $inserted;

		self::assertSame( count( DifficultyTaxonomy::DEFAULT_TERMS ), $after_first );
		self::assertSame(
			$after_first,
			$after_second,
			'Second call must not insert any terms — all slugs already exist.'
		);
	}
}
