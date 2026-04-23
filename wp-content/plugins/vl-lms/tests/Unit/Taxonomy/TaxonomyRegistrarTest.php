<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Taxonomy;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use VL\LMS\Taxonomy\AbstractTaxonomyRegistrar;
use VL\LMS\Taxonomy\CategoryTaxonomy;
use VL\LMS\Taxonomy\DifficultyTaxonomy;
use VL\LMS\Taxonomy\SpecialtyTaxonomy;
use VL\LMS\Taxonomy\TagTaxonomy;
use VL\LMS\Taxonomy\TaxonomyRegistrar;

final class TaxonomyRegistrarTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_constructor_seeds_all_taxonomy_registrars_in_order(): void {
		$registrar = new TaxonomyRegistrar();

		$registrars = $registrar->registrars();

		self::assertCount( 4, $registrars );
		self::assertInstanceOf( CategoryTaxonomy::class, $registrars[0] );
		self::assertInstanceOf( SpecialtyTaxonomy::class, $registrars[1] );
		self::assertInstanceOf( DifficultyTaxonomy::class, $registrars[2] );
		self::assertInstanceOf( TagTaxonomy::class, $registrars[3] );
	}

	public function test_register_hooks_attaches_register_all_to_init_with_priority_ten(): void {
		$registrar = new TaxonomyRegistrar();

		Actions\expectAdded( 'init' )
			->once()
			->with( [ $registrar, 'register_all' ], 10 );

		$registrar->register_hooks();
	}

	public function test_register_all_invokes_register_on_every_registrar(): void {
		$first  = Mockery::mock( AbstractTaxonomyRegistrar::class );
		$second = Mockery::mock( AbstractTaxonomyRegistrar::class );
		$first->shouldReceive( 'register' )->once();
		$second->shouldReceive( 'register' )->once();

		$registrar = new TaxonomyRegistrar();
		$this->replace_registrars( $registrar, [ $first, $second ] );

		$registrar->register_all();
	}

	/**
	 * @param list<AbstractTaxonomyRegistrar> $registrars
	 */
	private function replace_registrars( TaxonomyRegistrar $target, array $registrars ): void {
		$property = new ReflectionProperty( TaxonomyRegistrar::class, 'registrars' );
		$property->setValue( $target, $registrars );
	}
}
