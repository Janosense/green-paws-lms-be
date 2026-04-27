<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Catalog;

use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Catalog\TaxonomyTermTransformer;
use WP_Term;

final class TaxonomyTermTransformerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private TaxonomyTermTransformer $transformer;

	protected function setUp(): void {
		parent::setUp();
		$this->transformer = new TaxonomyTermTransformer();
	}

	public function test_flat_taxonomy_omits_parent_slug_key(): void {
		$out = $this->transformer->transform( $this->term( 5, 'therapist', 'Therapist', 'vl_specialty', 0, 12 ) );

		self::assertSame( 5, $out['id'] );
		self::assertSame( 'therapist', $out['slug'] );
		self::assertSame( 'Therapist', $out['name'] );
		self::assertSame( 12, $out['count'] );
		self::assertArrayNotHasKey( 'parent_slug', $out );
	}

	public function test_hierarchical_top_level_emits_null_parent_slug(): void {
		$out = $this->transformer->transform( $this->term( 7, 'cardiology', 'Cardiology', 'vl_category', 0, 4 ) );

		self::assertArrayHasKey( 'parent_slug', $out );
		self::assertNull( $out['parent_slug'] );
	}

	public function test_hierarchical_child_resolves_parent_slug_from_lookup(): void {
		$parent = $this->term( 7, 'cardiology', 'Cardiology', 'vl_category', 0, 4 );
		$child  = $this->term( 8, 'echocardiography', 'Echocardiography', 'vl_category', 7, 1 );

		$out = $this->transformer->transform( $child, [ 7 => $parent ] );

		self::assertSame( 'cardiology', $out['parent_slug'] );
	}

	public function test_hierarchical_child_yields_null_when_parent_missing_from_lookup(): void {
		$child = $this->term( 8, 'echocardiography', 'Echocardiography', 'vl_category', 7, 1 );

		$out = $this->transformer->transform( $child, [] );

		self::assertNull( $out['parent_slug'] );
	}

	public function test_is_hierarchical_only_true_for_vl_category(): void {
		self::assertTrue( $this->transformer->is_hierarchical( 'vl_category' ) );
		self::assertFalse( $this->transformer->is_hierarchical( 'vl_specialty' ) );
		self::assertFalse( $this->transformer->is_hierarchical( 'vl_difficulty' ) );
		self::assertFalse( $this->transformer->is_hierarchical( 'vl_tag' ) );
	}

	private function term(
		int $id,
		string $slug,
		string $name,
		string $taxonomy,
		int $parent,
		int $count
	): WP_Term {
		$term           = Mockery::mock( 'WP_Term' );
		$term->term_id  = $id;
		$term->slug     = $slug;
		$term->name     = $name;
		$term->taxonomy = $taxonomy;
		$term->parent   = $parent;
		$term->count    = $count;
		return $term;
	}
}
