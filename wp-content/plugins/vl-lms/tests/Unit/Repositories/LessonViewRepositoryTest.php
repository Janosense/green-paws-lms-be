<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Repositories;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Progress\LessonView;
use VL\LMS\Domain\Progress\ViewEventType;
use VL\LMS\Repositories\LessonViewRepository;

final class LessonViewRepositoryTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private LessonViewRepository $repo;

	/**
	 * @var Mockery\MockInterface
	 */
	private $wpdb;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WP core constant shim for tests.
		defined( 'ABSPATH' ) || define( 'ABSPATH', sys_get_temp_dir() . '/' );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WP core constant shim for tests.
		defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

		Functions\when( 'wp_json_encode' )->alias(
			static fn ( mixed $value ): string|false => json_encode( $value )
		);

		$this->wpdb         = Mockery::mock();
		$this->wpdb->prefix = 'wp_';
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test double for $wpdb.
		$GLOBALS['wpdb'] = $this->wpdb;

		$this->repo = new LessonViewRepository();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	private static function utc( string $value ): \DateTimeImmutable {
		return new \DateTimeImmutable( $value, new \DateTimeZone( 'UTC' ) );
	}

	/**
	 * @param array<string, mixed>|null $payload
	 *
	 * @return array<string, mixed>
	 */
	private static function row(
		int $id = 1,
		string $session_uuid = 'session-uuid',
		string $event_type = 'play',
		?array $payload = null,
		?int $topic_id = null
	): array {
		return [
			'id'               => (string) $id,
			'user_id'          => '5',
			'lesson_id'        => '101',
			'topic_id'         => null === $topic_id ? null : (string) $topic_id,
			'session_uuid'     => $session_uuid,
			'event_type'       => $event_type,
			'position_seconds' => '15',
			'payload'          => null === $payload ? null : json_encode( $payload ),
			'created_at'       => '2026-04-28 10:00:00',
		];
	}

	public function test_insert_writes_event_with_encoded_payload(): void {
		$captured_data = null;

		$this->wpdb->shouldReceive( 'insert' )
			->once()
			->andReturnUsing(
				function ( string $table, array $data ) use ( &$captured_data ): int {
					$captured_data = $data;
					return 1;
				}
			);
		$this->wpdb->insert_id = 88;
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )
			->once()
			->andReturn(
				self::row(
					88,
					'session-uuid',
					'seek',
					[
						'from' => 10,
						'to'   => 25,
					]
				)
			);

		$payload = [
			'from' => 10,
			'to'   => 25,
		];

		$result = $this->repo->insert(
			5,
			101,
			null,
			'session-uuid',
			ViewEventType::SEEK,
			25,
			$payload,
			self::utc( '2026-04-28 10:00:00' )
		);

		self::assertInstanceOf( LessonView::class, $result );
		self::assertSame( 88, $result->id );

		self::assertSame( 5, $captured_data['user_id'] );
		self::assertSame( 101, $captured_data['lesson_id'] );
		self::assertNull( $captured_data['topic_id'] );
		self::assertSame( 'session-uuid', $captured_data['session_uuid'] );
		self::assertSame( 'seek', $captured_data['event_type'] );
		self::assertSame( 25, $captured_data['position_seconds'] );
		self::assertSame( '2026-04-28 10:00:00', $captured_data['created_at'] );
		self::assertSame( json_encode( $payload ), $captured_data['payload'] );
	}

	public function test_insert_persists_null_payload_as_null(): void {
		$captured_data = null;

		$this->wpdb->shouldReceive( 'insert' )
			->once()
			->andReturnUsing(
				function ( string $table, array $data ) use ( &$captured_data ): int {
					$captured_data = $data;
					return 1;
				}
			);
		$this->wpdb->insert_id = 1;
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( self::row() );

		$this->repo->insert(
			5,
			101,
			null,
			'session-uuid',
			ViewEventType::PLAY,
			null,
			null,
			self::utc( '2026-04-28 10:00:00' )
		);

		self::assertNull( $captured_data['payload'] );
		self::assertNull( $captured_data['position_seconds'] );
	}

	public function test_find_by_id_returns_lesson_view_with_decoded_payload(): void {
		$payload = [
			'seek' => [
				'from' => 1,
				'to'   => 5,
			],
			'meta' => [
				'tags' => [ 'a', 'b' ],
			],
		];

		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )
			->once()
			->andReturn( self::row( 1, 'session-uuid', 'seek', $payload ) );

		$result = $this->repo->find_by_id( 1 );

		self::assertInstanceOf( LessonView::class, $result );
		self::assertSame( $payload, $result->payload );
		self::assertSame( ViewEventType::SEEK, $result->event_type );
	}

	public function test_find_by_id_returns_null_when_no_row(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )->andReturn( null );

		self::assertNull( $this->repo->find_by_id( 9999 ) );
	}

	public function test_list_for_session_returns_all_rows_for_session(): void {
		$captured_args = [];

		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->andReturnUsing(
				function ( string $sql, ...$args ) use ( &$captured_args ): string {
					$captured_args = $args;
					return $sql;
				}
			);
		$this->wpdb->shouldReceive( 'get_results' )
			->once()
			->andReturn(
				[
					self::row( 1, 'sess', 'view_start' ),
					self::row( 2, 'sess', 'play' ),
					self::row( 3, 'sess', 'unload' ),
				]
			);

		$rows = $this->repo->list_for_session( 'sess' );

		self::assertCount( 3, $rows );
		self::assertSame( [ 'sess' ], $captured_args );
		self::assertSame( ViewEventType::VIEW_START, $rows[0]->event_type );
		self::assertSame( ViewEventType::UNLOAD, $rows[2]->event_type );
	}

	public function test_list_for_session_returns_empty_array_when_no_rows(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_results' )->andReturn( [] );

		self::assertSame( [], $this->repo->list_for_session( 'unknown' ) );
	}

	public function test_delete_for_user_returns_int_count(): void {
		$this->wpdb->shouldReceive( 'delete' )
			->once()
			->with( Mockery::any(), [ 'user_id' => 5 ] )
			->andReturn( 12 );

		self::assertSame( 12, $this->repo->delete_for_user( 5 ) );
	}

	public function test_delete_for_user_returns_zero_on_wpdb_failure(): void {
		$this->wpdb->shouldReceive( 'delete' )->once()->andReturn( false );

		self::assertSame( 0, $this->repo->delete_for_user( 5 ) );
	}

	public function test_payload_round_trip_preserves_nested_structure(): void {
		$captured_data = null;
		$payload       = [
			'level1' => [
				'level2' => [
					'value' => 'deep',
					'list'  => [ 1, 2, 3 ],
					'mixed' => [
						'a' => null,
						'b' => false,
					],
				],
			],
		];

		$this->wpdb->shouldReceive( 'insert' )
			->once()
			->andReturnUsing(
				function ( string $table, array $data ) use ( &$captured_data ): int {
					$captured_data = $data;
					return 1;
				}
			);
		$this->wpdb->insert_id = 5;
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )
			->once()
			->andReturnUsing(
				static function () use ( &$captured_data ): array {
					return [
						'id'               => '5',
						'user_id'          => '1',
						'lesson_id'        => '101',
						'topic_id'         => null,
						'session_uuid'     => 'session',
						'event_type'       => 'progress',
						'position_seconds' => null,
						'payload'          => $captured_data['payload'],
						'created_at'       => '2026-04-28 10:00:00',
					];
				}
			);

		$result = $this->repo->insert(
			1,
			101,
			null,
			'session',
			ViewEventType::PROGRESS,
			null,
			$payload,
			self::utc( '2026-04-28 10:00:00' )
		);

		self::assertSame( $payload, $result->payload );
	}
}
