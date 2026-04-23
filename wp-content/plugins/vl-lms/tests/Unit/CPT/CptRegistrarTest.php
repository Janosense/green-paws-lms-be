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
use VL\LMS\CPT\LessonType;
use VL\LMS\CPT\ModuleType;
use VL\LMS\CPT\SessionType;
use VL\LMS\CPT\TopicType;

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

	public function test_constructor_seeds_course_module_lesson_topic_and_session_types_in_order(): void {
		$registrar = new CptRegistrar();

		$registrars = $registrar->registrars();

		self::assertCount( 5, $registrars );
		self::assertInstanceOf( CourseType::class, $registrars[0] );
		self::assertInstanceOf( ModuleType::class, $registrars[1] );
		self::assertInstanceOf( LessonType::class, $registrars[2] );
		self::assertInstanceOf( TopicType::class, $registrars[3] );
		self::assertInstanceOf( SessionType::class, $registrars[4] );
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
