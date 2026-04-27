<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Catalog;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use VL\LMS\Catalog\PostType;

final class PostTypeTest extends TestCase {

	public function test_cases_back_real_cpt_slugs(): void {
		self::assertSame( 'vl_course', PostType::COURSE->value );
		self::assertSame( 'vl_webinar', PostType::WEBINAR->value );
	}

	public function test_from_path_segment_resolves_courses_and_webinars(): void {
		self::assertSame( PostType::COURSE, PostType::from_path_segment( 'courses' ) );
		self::assertSame( PostType::WEBINAR, PostType::from_path_segment( 'webinars' ) );
	}

	public function test_from_path_segment_throws_on_unknown(): void {
		$this->expectException( InvalidArgumentException::class );
		PostType::from_path_segment( 'lessons' );
	}

	public function test_permalink_prefix_is_frontend_relative(): void {
		self::assertSame( '/courses/', PostType::COURSE->permalink_prefix() );
		self::assertSame( '/webinars/', PostType::WEBINAR->permalink_prefix() );
	}
}
