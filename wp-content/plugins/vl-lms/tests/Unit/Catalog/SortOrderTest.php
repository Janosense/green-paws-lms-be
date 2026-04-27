<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Catalog;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use VL\LMS\Catalog\PostType;
use VL\LMS\Catalog\SortOrder;

final class SortOrderTest extends TestCase {

	public function test_course_allows_four_sorts_without_upcoming(): void {
		$allowed = SortOrder::allowed_for( PostType::COURSE );

		self::assertContains( SortOrder::NEWEST, $allowed );
		self::assertContains( SortOrder::OLDEST, $allowed );
		self::assertContains( SortOrder::TITLE_ASC, $allowed );
		self::assertContains( SortOrder::TITLE_DESC, $allowed );
		self::assertNotContains( SortOrder::UPCOMING, $allowed );
		self::assertCount( 4, $allowed );
	}

	public function test_webinar_allows_all_five_sorts_including_upcoming(): void {
		$allowed = SortOrder::allowed_for( PostType::WEBINAR );

		self::assertContains( SortOrder::UPCOMING, $allowed );
		self::assertCount( 5, $allowed );
	}

	public function test_from_string_resolves_known_value(): void {
		self::assertSame( SortOrder::NEWEST, SortOrder::from_string( 'newest', PostType::COURSE ) );
		self::assertSame( SortOrder::TITLE_ASC, SortOrder::from_string( 'title-asc', PostType::COURSE ) );
		self::assertSame( SortOrder::UPCOMING, SortOrder::from_string( 'upcoming', PostType::WEBINAR ) );
	}

	public function test_from_string_rejects_upcoming_for_courses(): void {
		$this->expectException( InvalidArgumentException::class );
		SortOrder::from_string( 'upcoming', PostType::COURSE );
	}

	public function test_from_string_rejects_unknown_value(): void {
		$this->expectException( InvalidArgumentException::class );
		SortOrder::from_string( 'banana', PostType::COURSE );
	}

	public function test_default_for_returns_newest_for_both_post_types(): void {
		self::assertSame( SortOrder::NEWEST, SortOrder::default_for( PostType::COURSE ) );
		self::assertSame( SortOrder::NEWEST, SortOrder::default_for( PostType::WEBINAR ) );
	}
}
