<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Taxonomy;

use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use VL\LMS\Taxonomy\DifficultyTaxonomy;

final class DifficultyTaxonomyTest extends TestCase {

	use MockeryPHPUnitIntegration;

	public function test_taxonomy_returns_vl_difficulty(): void {
		self::assertSame( 'vl_difficulty', $this->invoke_protected( 'taxonomy' ) );
	}

	public function test_object_types_targets_course_and_webinar(): void {
		self::assertSame( [ 'vl_course', 'vl_webinar' ], $this->invoke_protected( 'object_types' ) );
	}

	public function test_hierarchical_is_false(): void {
		self::assertFalse( $this->invoke_protected( 'hierarchical' ) );
	}

	public function test_capabilities_ties_term_management_to_manage_options_and_assignment_to_edit_posts(): void {
		$capabilities = $this->invoke_protected( 'capabilities' );

		self::assertIsArray( $capabilities );
		self::assertSame(
			[
				'manage_terms' => 'manage_options',
				'edit_terms'   => 'manage_options',
				'delete_terms' => 'manage_options',
				'assign_terms' => 'edit_posts',
			],
			$capabilities
		);
	}

	public function test_default_terms_constant_lists_basic_advanced_expert(): void {
		self::assertCount( 3, DifficultyTaxonomy::DEFAULT_TERMS );
		self::assertSame(
			[
				'basic'    => 'Basic',
				'advanced' => 'Advanced',
				'expert'   => 'Expert',
			],
			DifficultyTaxonomy::DEFAULT_TERMS
		);
	}

	private function invoke_protected( string $method ): mixed {
		$reflection = new ReflectionMethod( DifficultyTaxonomy::class, $method );
		return $reflection->invoke( new DifficultyTaxonomy() );
	}
}
