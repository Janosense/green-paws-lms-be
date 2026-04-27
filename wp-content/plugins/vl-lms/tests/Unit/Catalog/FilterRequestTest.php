<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Catalog;

use Brain\Monkey;
use Brain\Monkey\Functions;
use InvalidArgumentException;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Catalog\FilterRequest;
use VL\LMS\Catalog\PostType;
use VL\LMS\Catalog\SortOrder;

final class FilterRequestTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// `sanitize_text_field` and `sanitize_title` are WP runtime helpers;
		// the value objects rely on them but the tests only need pass-through
		// behaviour, normalising whitespace where useful.
		Functions\when( 'sanitize_text_field' )->alias(
			static fn ( mixed $v ): string => is_string( $v ) ? trim( $v ) : ''
		);
		Functions\when( 'sanitize_title' )->alias(
			static fn ( mixed $v ): string => is_string( $v ) ? strtolower( trim( $v ) ) : ''
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_empty_input_yields_defaults(): void {
		$req = FilterRequest::from_array( PostType::COURSE, [] );

		self::assertSame( '', $req->q );
		self::assertSame( [], $req->categories );
		self::assertSame( [], $req->specialties );
		self::assertSame( [], $req->difficulties );
		self::assertSame( [], $req->tags );
		self::assertSame( 1, $req->page );
		self::assertSame( FilterRequest::PER_PAGE_DEFAULT, $req->per_page );
		self::assertSame( SortOrder::NEWEST, $req->sort );
	}

	public function test_q_is_trimmed_and_truncated_at_200_chars(): void {
		$long = str_repeat( 'a', 250 );
		$req  = FilterRequest::from_array( PostType::COURSE, [ 'q' => "  {$long}  " ] );

		self::assertSame( 200, strlen( $req->q ) );
		self::assertSame( str_repeat( 'a', 200 ), $req->q );
	}

	public function test_slug_arrays_drop_empty_values_and_dedupe(): void {
		$req = FilterRequest::from_array(
			PostType::COURSE,
			[
				'category' => [ 'cardiology', '', 'surgery', 'cardiology' ],
			]
		);

		self::assertSame( [ 'cardiology', 'surgery' ], $req->categories );
	}

	public function test_per_page_clamps_into_bounds(): void {
		$too_low = FilterRequest::from_array( PostType::COURSE, [ 'per_page' => 0 ] );
		self::assertSame( FilterRequest::PER_PAGE_MIN, $too_low->per_page );

		$too_high = FilterRequest::from_array( PostType::COURSE, [ 'per_page' => 200 ] );
		self::assertSame( FilterRequest::PER_PAGE_MAX, $too_high->per_page );

		$ok = FilterRequest::from_array( PostType::COURSE, [ 'per_page' => 24 ] );
		self::assertSame( 24, $ok->per_page );
	}

	public function test_page_minimum_is_1(): void {
		$req = FilterRequest::from_array( PostType::COURSE, [ 'page' => -5 ] );
		self::assertSame( 1, $req->page );
	}

	public function test_unknown_sort_throws(): void {
		$this->expectException( InvalidArgumentException::class );
		FilterRequest::from_array( PostType::COURSE, [ 'sort' => 'banana' ] );
	}

	public function test_upcoming_sort_rejected_for_courses(): void {
		$this->expectException( InvalidArgumentException::class );
		FilterRequest::from_array( PostType::COURSE, [ 'sort' => 'upcoming' ] );
	}

	public function test_upcoming_sort_accepted_for_webinars(): void {
		$req = FilterRequest::from_array( PostType::WEBINAR, [ 'sort' => 'upcoming' ] );
		self::assertSame( SortOrder::UPCOMING, $req->sort );
	}

	public function test_single_string_filter_value_is_treated_as_one_item(): void {
		$req = FilterRequest::from_array(
			PostType::COURSE,
			[ 'specialty' => 'therapist' ]
		);
		self::assertSame( [ 'therapist' ], $req->specialties );
	}
}
