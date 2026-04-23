<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\CPT;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use VL\LMS\CPT\AbstractCptRegistrar;
use VL\LMS\CPT\CourseType;
use VL\LMS\CPT\CptRegistrar;

final class CptRegistrarTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_constructor_seeds_at_least_the_course_type_registrar(): void {
		$registrar = new CptRegistrar();

		$registrars = $registrar->registrars();

		self::assertGreaterThanOrEqual( 1, count( $registrars ) );
		self::assertInstanceOf( CourseType::class, $registrars[0] );
	}

	public function test_register_hooks_attaches_register_all_to_init_with_priority_ten(): void {
		$registrar = new CptRegistrar();

		Actions\expectAdded( 'init' )
			->once()
			->with( [ $registrar, 'register_all' ], 10 );

		$registrar->register_hooks();
	}

	public function test_register_all_invokes_register_on_every_registrar(): void {
		$first  = Mockery::mock( AbstractCptRegistrar::class );
		$second = Mockery::mock( AbstractCptRegistrar::class );
		$first->shouldReceive( 'register' )->once();
		$second->shouldReceive( 'register' )->once();

		$registrar = new CptRegistrar();
		$this->replace_registrars( $registrar, [ $first, $second ] );

		$registrar->register_all();
	}

	/**
	 * @param list<AbstractCptRegistrar> $registrars
	 */
	private function replace_registrars( CptRegistrar $target, array $registrars ): void {
		$property = new ReflectionProperty( CptRegistrar::class, 'registrars' );
		$property->setValue( $target, $registrars );
	}
}
