<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Services\Progress;

use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Progress\EntityType;
use VL\LMS\Domain\Progress\ViewEventType;
use VL\LMS\Services\Progress\ProgressEventRequest;

final class ProgressEventRequestTest extends TestCase {

	private const string VALID_UUID = '8c7e9f2a-2c1d-4d2c-9e89-3f5d2a3b4c5d';

	/**
	 * @return array<string, mixed>
	 */
	private static function valid( array $overrides = [] ): array {
		return array_merge(
			[
				'entity_type'      => 'lesson',
				'entity_id'        => 123,
				'session_uuid'     => self::VALID_UUID,
				'event_type'       => 'progress',
				'position_seconds' => 240,
				'payload'          => null,
			],
			$overrides
		);
	}

	public function test_happy_path_lesson_progress(): void {
		$request = ProgressEventRequest::fromArray( self::valid() );

		self::assertSame( EntityType::LESSON, $request->entity_type );
		self::assertSame( 123, $request->entity_id );
		self::assertSame( self::VALID_UUID, $request->session_uuid );
		self::assertSame( ViewEventType::PROGRESS, $request->event_type );
		self::assertSame( 240, $request->position_seconds );
		self::assertNull( $request->payload );
	}

	/**
	 * @dataProvider provide_event_type_x_entity_type
	 */
	public function test_happy_path_for_each_event_type_and_entity_type(
		string $entity_type,
		string $event_type
	): void {
		$request = ProgressEventRequest::fromArray(
			self::valid(
				[
					'entity_type' => $entity_type,
					'event_type'  => $event_type,
				]
			)
		);

		self::assertSame( $entity_type, $request->entity_type->value );
		self::assertSame( $event_type, $request->event_type->value );
	}

	/**
	 * @return iterable<string, array{0: string, 1: string}>
	 */
	public static function provide_event_type_x_entity_type(): iterable {
		foreach ( [ 'lesson', 'topic' ] as $entity_type ) {
			foreach ( [ 'view_start', 'play', 'pause', 'seek', 'progress', 'complete', 'unload' ] as $event_type ) {
				yield $entity_type . '+' . $event_type => [ $entity_type, $event_type ];
			}
		}
	}

	public function test_missing_entity_type_throws_stable_code(): void {
		$payload = self::valid();
		unset( $payload['entity_type'] );

		try {
			ProgressEventRequest::fromArray( $payload );
			self::fail( 'Expected InvalidArgumentException.' );
		} catch ( \InvalidArgumentException $e ) {
			self::assertSame( 'missing_field:entity_type', $e->getMessage() );
		}
	}

	public function test_missing_entity_id_throws_stable_code(): void {
		$payload = self::valid();
		unset( $payload['entity_id'] );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'missing_field:entity_id' );
		ProgressEventRequest::fromArray( $payload );
	}

	public function test_missing_session_uuid_throws_stable_code(): void {
		$payload = self::valid();
		unset( $payload['session_uuid'] );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'missing_field:session_uuid' );
		ProgressEventRequest::fromArray( $payload );
	}

	public function test_missing_event_type_throws_stable_code(): void {
		$payload = self::valid();
		unset( $payload['event_type'] );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'missing_field:event_type' );
		ProgressEventRequest::fromArray( $payload );
	}

	public function test_string_entity_id_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'invalid_type:entity_id' );
		ProgressEventRequest::fromArray( self::valid( [ 'entity_id' => '123' ] ) );
	}

	public function test_int_session_uuid_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'invalid_type:session_uuid' );
		ProgressEventRequest::fromArray( self::valid( [ 'session_uuid' => 123 ] ) );
	}

	public function test_negative_entity_id_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'invalid_range:entity_id' );
		ProgressEventRequest::fromArray( self::valid( [ 'entity_id' => -5 ] ) );
	}

	public function test_zero_entity_id_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		ProgressEventRequest::fromArray( self::valid( [ 'entity_id' => 0 ] ) );
	}

	public function test_negative_position_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'invalid_range:position_seconds' );
		ProgressEventRequest::fromArray( self::valid( [ 'position_seconds' => -1 ] ) );
	}

	public function test_module_entity_type_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'invalid_entity_type' );
		ProgressEventRequest::fromArray( self::valid( [ 'entity_type' => 'module' ] ) );
	}

	public function test_unknown_entity_type_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'invalid_entity_type' );
		ProgressEventRequest::fromArray( self::valid( [ 'entity_type' => 'foobar' ] ) );
	}

	public function test_unknown_event_type_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'invalid_event_type' );
		ProgressEventRequest::fromArray( self::valid( [ 'event_type' => 'foobar' ] ) );
	}

	public function test_uppercase_uuid_is_accepted(): void {
		$request = ProgressEventRequest::fromArray(
			self::valid( [ 'session_uuid' => strtoupper( self::VALID_UUID ) ] )
		);
		self::assertSame( strtoupper( self::VALID_UUID ), $request->session_uuid );
	}

	public function test_uuid_without_hyphens_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'invalid_uuid' );
		ProgressEventRequest::fromArray(
			self::valid( [ 'session_uuid' => str_replace( '-', '', self::VALID_UUID ) ] )
		);
	}

	public function test_short_uuid_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'invalid_uuid' );
		ProgressEventRequest::fromArray( self::valid( [ 'session_uuid' => '8c7e9f2a' ] ) );
	}

	public function test_empty_session_uuid_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'invalid_type:session_uuid' );
		ProgressEventRequest::fromArray( self::valid( [ 'session_uuid' => '' ] ) );
	}

	public function test_position_seconds_null_is_accepted(): void {
		$request = ProgressEventRequest::fromArray( self::valid( [ 'position_seconds' => null ] ) );
		self::assertNull( $request->position_seconds );
	}

	public function test_position_seconds_zero_is_accepted(): void {
		$request = ProgressEventRequest::fromArray( self::valid( [ 'position_seconds' => 0 ] ) );
		self::assertSame( 0, $request->position_seconds );
	}

	public function test_payload_object_is_accepted(): void {
		$payload = [
			'from' => 30,
			'to'   => 90,
		];
		$request = ProgressEventRequest::fromArray(
			self::valid(
				[
					'event_type' => 'seek',
					'payload'    => $payload,
				]
			)
		);
		self::assertSame( $payload, $request->payload );
	}

	public function test_empty_payload_object_is_accepted(): void {
		$request = ProgressEventRequest::fromArray( self::valid( [ 'payload' => [] ] ) );
		self::assertSame( [], $request->payload );
	}

	public function test_list_payload_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'invalid_type:payload' );
		ProgressEventRequest::fromArray( self::valid( [ 'payload' => [ 1, 2, 3 ] ] ) );
	}

	public function test_payload_over_4kb_throws_payload_too_large(): void {
		$big = [ 'blob' => str_repeat( 'a', 5000 ) ];

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'payload_too_large' );
		ProgressEventRequest::fromArray( self::valid( [ 'payload' => $big ] ) );
	}

	public function test_payload_at_exactly_4kb_is_accepted(): void {
		$pad = str_repeat( 'a', ProgressEventRequest::PAYLOAD_MAX_BYTES - 12 );
		$ok  = [ 'k' => $pad ];
		// Sanity-check that the test fixture lands at the boundary.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- mirrors the boundary-size check in the SUT.
		$encoded = json_encode( $ok );
		self::assertNotFalse( $encoded );
		self::assertLessThanOrEqual( ProgressEventRequest::PAYLOAD_MAX_BYTES, strlen( $encoded ) );

		$request = ProgressEventRequest::fromArray( self::valid( [ 'payload' => $ok ] ) );
		self::assertSame( $ok, $request->payload );
	}
}
