<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Catalog\Search;

use Brain\Monkey;
use Brain\Monkey\Functions;
use InvalidArgumentException;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Catalog\Search\SearchRequest;

final class SearchRequestTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'sanitize_text_field' )->alias(
			static fn ( mixed $v ): string => is_string( $v ) ? trim( $v ) : ''
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_missing_q_throws(): void {
		$this->expectException( InvalidArgumentException::class );
		SearchRequest::from_array( [] );
	}

	public function test_empty_q_throws(): void {
		$this->expectException( InvalidArgumentException::class );
		SearchRequest::from_array( [ 'q' => '' ] );
	}

	public function test_whitespace_only_q_throws(): void {
		$this->expectException( InvalidArgumentException::class );
		SearchRequest::from_array( [ 'q' => "   \t\n  " ] );
	}

	public function test_non_string_q_throws(): void {
		$this->expectException( InvalidArgumentException::class );
		SearchRequest::from_array( [ 'q' => [ 'array', 'not', 'string' ] ] );
	}

	public function test_q_is_trimmed_and_truncated_at_200_chars(): void {
		$long = str_repeat( 'a', 250 );
		$req  = SearchRequest::from_array( [ 'q' => "  {$long}  " ] );

		self::assertSame( 200, strlen( $req->q ) );
		self::assertSame( str_repeat( 'a', 200 ), $req->q );
	}

	public function test_defaults_for_pagination(): void {
		$req = SearchRequest::from_array( [ 'q' => 'cardiology' ] );

		self::assertSame( 1, $req->page );
		self::assertSame( SearchRequest::PER_PAGE_DEFAULT, $req->per_page );
	}

	public function test_per_page_clamps_into_bounds(): void {
		$too_low = SearchRequest::from_array(
			[
				'q'        => 'x',
				'per_page' => 0,
			]
		);
		self::assertSame( SearchRequest::PER_PAGE_MIN, $too_low->per_page );

		$too_high = SearchRequest::from_array(
			[
				'q'        => 'x',
				'per_page' => 200,
			]
		);
		self::assertSame( SearchRequest::PER_PAGE_MAX, $too_high->per_page );

		$ok = SearchRequest::from_array(
			[
				'q'        => 'x',
				'per_page' => 24,
			]
		);
		self::assertSame( 24, $ok->per_page );
	}

	public function test_page_minimum_is_1(): void {
		$req = SearchRequest::from_array(
			[
				'q'    => 'x',
				'page' => -5,
			]
		);
		self::assertSame( 1, $req->page );
	}

	public function test_non_numeric_pagination_uses_defaults(): void {
		$req = SearchRequest::from_array(
			[
				'q'        => 'x',
				'page'     => 'banana',
				'per_page' => 'banana',
			]
		);
		self::assertSame( 1, $req->page );
		self::assertSame( SearchRequest::PER_PAGE_DEFAULT, $req->per_page );
	}
}
