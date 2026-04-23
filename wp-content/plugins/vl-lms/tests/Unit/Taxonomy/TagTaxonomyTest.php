<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Taxonomy;

use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use VL\LMS\Taxonomy\TagTaxonomy;

final class TagTaxonomyTest extends TestCase {

	use MockeryPHPUnitIntegration;

	public function test_taxonomy_returns_vl_tag(): void {
		self::assertSame( 'vl_tag', $this->invoke_protected( 'taxonomy' ) );
	}

	public function test_object_types_targets_course_webinar_and_lesson(): void {
		self::assertSame(
			[ 'vl_course', 'vl_webinar', 'vl_lesson' ],
			$this->invoke_protected( 'object_types' )
		);
	}

	public function test_hierarchical_is_false(): void {
		self::assertFalse( $this->invoke_protected( 'hierarchical' ) );
	}

	public function test_capabilities_returns_null(): void {
		self::assertNull( $this->invoke_protected( 'capabilities' ) );
	}

	private function invoke_protected( string $method ): mixed {
		$reflection = new ReflectionMethod( TagTaxonomy::class, $method );
		return $reflection->invoke( new TagTaxonomy() );
	}
}
