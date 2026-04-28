<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Catalog\Search;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Catalog\PostType;
use VL\LMS\Catalog\Search\SearchQuery;
use VL\LMS\Catalog\Search\SearchRequest;

final class SearchQueryTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private SearchQuery $builder;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'sanitize_text_field' )->returnArg();

		$this->builder = new SearchQuery();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_builds_args_for_courses(): void {
		$req  = SearchRequest::from_array( [ 'q' => 'cardiology' ] );
		$args = $this->builder->build( PostType::COURSE, $req );

		self::assertSame( 'vl_course', $args['post_type'] );
		self::assertSame( 'publish', $args['post_status'] );
		self::assertSame( 'cardiology', $args['s'] );
		self::assertSame( 1, $args['paged'] );
		self::assertSame( SearchRequest::PER_PAGE_DEFAULT, $args['posts_per_page'] );
	}

	public function test_builds_args_for_webinars(): void {
		$req  = SearchRequest::from_array( [ 'q' => 'cardiology' ] );
		$args = $this->builder->build( PostType::WEBINAR, $req );

		self::assertSame( 'vl_webinar', $args['post_type'] );
		self::assertSame( 'publish', $args['post_status'] );
		self::assertSame( 'cardiology', $args['s'] );
	}

	public function test_pagination_args_propagated(): void {
		$req  = SearchRequest::from_array(
			[
				'q'        => 'echo',
				'page'     => 3,
				'per_page' => 24,
			]
		);
		$args = $this->builder->build( PostType::COURSE, $req );

		self::assertSame( 3, $args['paged'] );
		self::assertSame( 24, $args['posts_per_page'] );
	}

	public function test_no_taxonomy_or_meta_query_emitted(): void {
		$req  = SearchRequest::from_array( [ 'q' => 'cardiology' ] );
		$args = $this->builder->build( PostType::COURSE, $req );

		self::assertArrayNotHasKey( 'tax_query', $args );
		self::assertArrayNotHasKey( 'meta_query', $args );
	}
}
