<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Taxonomy;

use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use VL\LMS\Taxonomy\CategoryTaxonomy;

final class CategoryTaxonomyTest extends TestCase {

	use MockeryPHPUnitIntegration;

	public function test_taxonomy_returns_vl_category(): void {
		self::assertSame( 'vl_category', $this->invoke_protected( 'taxonomy' ) );
	}

	public function test_object_types_targets_course_and_webinar(): void {
		self::assertSame( [ 'vl_course', 'vl_webinar' ], $this->invoke_protected( 'object_types' ) );
	}

	public function test_hierarchical_is_true(): void {
		self::assertTrue( $this->invoke_protected( 'hierarchical' ) );
	}

	public function test_capabilities_returns_null(): void {
		self::assertNull( $this->invoke_protected( 'capabilities' ) );
	}

	private function invoke_protected( string $method ): mixed {
		$reflection = new ReflectionMethod( CategoryTaxonomy::class, $method );
		return $reflection->invoke( new CategoryTaxonomy() );
	}
}
