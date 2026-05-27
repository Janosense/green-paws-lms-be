<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\CPT;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use VL\LMS\CPT\ModuleType;

final class ModuleTypeTest extends TestCase {

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

	public function test_post_type_returns_vl_module(): void {
		self::assertSame( 'vl_module', $this->invoke_protected( 'post_type' ) );
	}

	public function test_capability_type_pair(): void {
		self::assertSame( [ 'vl_module', 'vl_modules' ], $this->invoke_protected( 'capability_type' ) );
	}

	public function test_supports_contains_exactly_required_features(): void {
		self::assertSame(
			[ 'title', 'editor', 'thumbnail', 'custom-fields', 'page-attributes' ],
			$this->invoke_protected( 'supports' )
		);
	}

	public function test_menu_icon_is_dashicons_portfolio(): void {
		self::assertSame( 'dashicons-portfolio', $this->invoke_protected( 'menu_icon' ) );
	}

	public function test_hierarchical_returns_false(): void {
		self::assertFalse( $this->invoke_protected( 'hierarchical' ) );
	}

	public function test_meta_fields_is_empty(): void {
		self::assertSame( [], $this->invoke_protected( 'meta_fields' ) );
	}

	private function invoke_protected( string $method ): mixed {
		$reflection = new ReflectionMethod( ModuleType::class, $method );
		return $reflection->invoke( new ModuleType() );
	}
}
