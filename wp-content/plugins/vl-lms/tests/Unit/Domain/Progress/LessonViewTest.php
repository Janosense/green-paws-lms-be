<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Domain\Progress;

use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Progress\LessonView;
use VL\LMS\Domain\Progress\ViewEventType;

final class LessonViewTest extends TestCase {

	private static function utc( string $value ): \DateTimeImmutable {
		return new \DateTimeImmutable( $value, new \DateTimeZone( 'UTC' ) );
	}

	public function test_constructor_assigns_every_property(): void {
		$created = self::utc( '2026-04-28 10:00:00' );

		$view = new LessonView(
			99,
			5,
			101,
			null,
			'8e2c4d2a-0000-4000-8000-000000000001',
			ViewEventType::PLAY,
			12,
			[ 'foo' => 'bar' ],
			$created
		);

		self::assertSame( 99, $view->id );
		self::assertSame( 5, $view->user_id );
		self::assertSame( 101, $view->lesson_id );
		self::assertNull( $view->topic_id );
		self::assertSame( '8e2c4d2a-0000-4000-8000-000000000001', $view->session_uuid );
		self::assertSame( ViewEventType::PLAY, $view->event_type );
		self::assertSame( 12, $view->position_seconds );
		self::assertSame( [ 'foo' => 'bar' ], $view->payload );
		self::assertSame( $created, $view->created_at );
	}

	public function test_payload_can_be_null(): void {
		$view = new LessonView(
			1,
			5,
			101,
			null,
			'session',
			ViewEventType::VIEW_START,
			null,
			null,
			self::utc( '2026-04-28 10:00:00' )
		);

		self::assertNull( $view->payload );
	}

	public function test_payload_can_be_nested_array(): void {
		$payload = [
			'seek' => [
				'from' => 10,
				'to'   => 25,
			],
			'meta' => [
				'tags' => [ 'a', 'b' ],
			],
		];

		$view = new LessonView(
			1,
			5,
			101,
			null,
			'session',
			ViewEventType::SEEK,
			25,
			$payload,
			self::utc( '2026-04-28 10:00:00' )
		);

		self::assertSame( $payload, $view->payload );
		self::assertSame( 25, $view->payload['seek']['to'] );
		self::assertSame( [ 'a', 'b' ], $view->payload['meta']['tags'] );
	}

	public function test_topic_id_can_be_provided(): void {
		$view = new LessonView(
			1,
			5,
			101,
			202,
			'session',
			ViewEventType::PROGRESS,
			60,
			null,
			self::utc( '2026-04-28 10:00:00' )
		);

		self::assertSame( 202, $view->topic_id );
	}

	public function test_properties_are_readonly(): void {
		$view = new LessonView(
			1,
			5,
			101,
			null,
			'session',
			ViewEventType::PLAY,
			null,
			null,
			self::utc( '2026-04-28 10:00:00' )
		);

		$this->expectException( \Error::class );
		// @phpstan-ignore-next-line Property.ReadOnlyAssignNotInScope
		$view->event_type = ViewEventType::PAUSE;
	}
}
