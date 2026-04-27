<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Catalog;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Catalog\CatalogQuery;
use VL\LMS\Catalog\FilterRequest;
use VL\LMS\Catalog\PostType;
use VL\LMS\Catalog\SortOrder;

final class CatalogQueryTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private CatalogQuery $builder;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_title' )->alias(
			static fn ( mixed $v ): string => is_string( $v ) ? strtolower( trim( $v ) ) : ''
		);

		$this->builder = new CatalogQuery();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_empty_filters_produce_baseline_args(): void {
		$args = $this->builder->build(
			FilterRequest::from_array( PostType::COURSE, [] )
		);

		self::assertSame( 'vl_course', $args['post_type'] );
		self::assertSame( 'publish', $args['post_status'] );
		self::assertSame( 1, $args['paged'] );
		self::assertSame( FilterRequest::PER_PAGE_DEFAULT, $args['posts_per_page'] );
		self::assertArrayNotHasKey( 's', $args );
		self::assertArrayNotHasKey( 'tax_query', $args );
		self::assertArrayNotHasKey( 'meta_query', $args );
		self::assertSame( 'date', $args['orderby'] );
		self::assertSame( 'DESC', $args['order'] );
	}

	public function test_q_only_passes_through_to_search_param(): void {
		$args = $this->builder->build(
			FilterRequest::from_array( PostType::COURSE, [ 'q' => 'cardiology' ] )
		);

		self::assertSame( 'cardiology', $args['s'] );
		self::assertArrayNotHasKey( 'tax_query', $args );
	}

	public function test_single_taxonomy_multi_slug_uses_in_relation(): void {
		$args = $this->builder->build(
			FilterRequest::from_array(
				PostType::COURSE,
				[ 'category' => [ 'cardiology', 'surgery' ] ]
			)
		);

		self::assertCount( 1, $args['tax_query'] );
		self::assertSame(
			[
				'taxonomy' => 'vl_category',
				'field'    => 'slug',
				'terms'    => [ 'cardiology', 'surgery' ],
				'operator' => 'IN',
			],
			$args['tax_query'][0]
		);
	}

	public function test_multi_taxonomy_uses_and_relation(): void {
		$args = $this->builder->build(
			FilterRequest::from_array(
				PostType::COURSE,
				[
					'category'   => [ 'cardiology' ],
					'specialty'  => [ 'therapist' ],
					'difficulty' => [ 'basic' ],
				]
			)
		);

		self::assertSame( 'AND', $args['tax_query']['relation'] );

		// Three clauses + relation key.
		self::assertCount( 4, $args['tax_query'] );

		$clauses    = array_filter(
			$args['tax_query'],
			static fn ( $clause ): bool => is_array( $clause )
		);
		$taxonomies = array_map(
			static fn ( array $c ): string => (string) $c['taxonomy'],
			array_values( $clauses )
		);
		self::assertSame( [ 'vl_category', 'vl_specialty', 'vl_difficulty' ], $taxonomies );
	}

	public function test_q_combines_with_filters(): void {
		$args = $this->builder->build(
			FilterRequest::from_array(
				PostType::COURSE,
				[
					'q'        => 'echo',
					'category' => [ 'cardiology' ],
				]
			)
		);

		self::assertSame( 'echo', $args['s'] );
		self::assertArrayHasKey( 'tax_query', $args );
	}

	public function test_pagination_args_propagated(): void {
		$args = $this->builder->build(
			FilterRequest::from_array(
				PostType::COURSE,
				[
					'page'     => 3,
					'per_page' => 24,
				]
			)
		);

		self::assertSame( 3, $args['paged'] );
		self::assertSame( 24, $args['posts_per_page'] );
	}

	public function test_title_asc_sort_overrides_default(): void {
		$args = $this->builder->build(
			FilterRequest::from_array( PostType::COURSE, [ 'sort' => 'title-asc' ] )
		);

		self::assertSame( 'title', $args['orderby'] );
		self::assertSame( 'ASC', $args['order'] );
	}

	public function test_upcoming_sort_layers_status_and_start_date_meta_query(): void {
		$req = FilterRequest::from_array( PostType::WEBINAR, [ 'sort' => 'upcoming' ] );

		$args = $this->builder->build( $req );

		self::assertSame( SortOrder::UPCOMING, $req->sort );

		self::assertArrayHasKey( 'meta_query', $args );
		self::assertSame( 'AND', $args['meta_query']['relation'] );
		self::assertSame(
			[
				'key'     => '_vl_webinar_status',
				'value'   => 'scheduled',
				'compare' => '=',
			],
			$args['meta_query']['status']
		);
		self::assertSame( '_vl_webinar_scheduled_start', $args['meta_query']['start_date']['key'] );
		self::assertSame( '>=', $args['meta_query']['start_date']['compare'] );
		self::assertSame( [ 'start_date' => 'ASC' ], $args['orderby'] );
	}
}
