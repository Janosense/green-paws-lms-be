<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Domain\Progress;

use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Progress\EntityType;

final class EntityTypeTest extends TestCase {

	public function test_from_post_type_maps_lesson(): void {
		self::assertSame( EntityType::LESSON, EntityType::fromPostType( 'vl_lesson' ) );
	}

	public function test_from_post_type_maps_topic(): void {
		self::assertSame( EntityType::TOPIC, EntityType::fromPostType( 'vl_topic' ) );
	}

	public function test_from_post_type_maps_module(): void {
		self::assertSame( EntityType::MODULE, EntityType::fromPostType( 'vl_module' ) );
	}

	public function test_from_post_type_throws_value_error_on_unknown(): void {
		$this->expectException( \ValueError::class );
		EntityType::fromPostType( 'vl_unknown' );
	}

	public function test_from_post_type_throws_value_error_on_unrelated_post_type(): void {
		$this->expectException( \ValueError::class );
		EntityType::fromPostType( 'post' );
	}

	public function test_from_string_round_trips_known_values(): void {
		self::assertSame( EntityType::LESSON, EntityType::from_string( 'lesson' ) );
		self::assertSame( EntityType::TOPIC, EntityType::from_string( 'topic' ) );
		self::assertSame( EntityType::MODULE, EntityType::from_string( 'module' ) );
	}

	public function test_from_string_rejects_unknown(): void {
		$this->expectException( \InvalidArgumentException::class );
		EntityType::from_string( 'quiz' );
	}
}
