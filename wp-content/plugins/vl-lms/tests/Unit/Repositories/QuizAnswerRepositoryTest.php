<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Repositories;

use Brain\Monkey;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Quiz\QuizAnswer;
use VL\LMS\Repositories\QuizAnswerRepository;

final class QuizAnswerRepositoryTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private QuizAnswerRepository $repo;

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

		$this->wpdb         = Mockery::mock();
		$this->wpdb->prefix = 'wp_';
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test double for $wpdb.
		$GLOBALS['wpdb'] = $this->wpdb;

		$this->repo = new QuizAnswerRepository();
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
	 * @return array<string, mixed>
	 */
	private static function row(
		int $id = 5,
		int $attempt_id = 17,
		int $question_id = 202,
		?int $is_correct = null,
		?int $points_awarded = null
	): array {
		return [
			'id'             => (string) $id,
			'attempt_id'     => (string) $attempt_id,
			'question_id'    => (string) $question_id,
			'answer_data'    => '{"answer_id":"a-uuid"}',
			'is_correct'     => null === $is_correct ? null : (string) $is_correct,
			'points_awarded' => null === $points_awarded ? null : (string) $points_awarded,
			'answered_at'    => '2026-04-28 10:05:00',
		];
	}

	public function test_find_returns_quiz_answer_for_existing_id(): void {
		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( self::row( 5 ) );

		$result = $this->repo->find( 5 );

		self::assertInstanceOf( QuizAnswer::class, $result );
		self::assertSame( 5, $result->id );
	}

	public function test_find_returns_null_when_no_row(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )->andReturn( null );

		self::assertNull( $this->repo->find( 999 ) );
	}

	public function test_find_by_attempt_and_question_passes_two_keys(): void {
		$captured_args = [];

		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->andReturnUsing(
				function ( string $sql, ...$args ) use ( &$captured_args ): string {
					$captured_args = $args;
					return $sql;
				}
			);
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( self::row() );

		$this->repo->find_by_attempt_and_question( 17, 202 );

		self::assertSame( [ 17, 202 ], $captured_args );
	}

	public function test_list_for_attempt_orders_by_id_ascending(): void {
		$captured_sql = '';

		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->andReturnUsing(
				function ( string $sql ) use ( &$captured_sql ): string {
					$captured_sql = $sql;
					return $sql;
				}
			);
		$this->wpdb->shouldReceive( 'get_results' )
			->once()
			->andReturn( [ self::row( 1 ), self::row( 2 ), self::row( 3 ) ] );

		$rows = $this->repo->list_for_attempt( 17 );

		self::assertCount( 3, $rows );
		self::assertStringContainsString( 'ORDER BY id ASC', $captured_sql );
	}

	public function test_upsert_uses_on_duplicate_key_update_for_atomicity(): void {
		$captured_sql  = '';
		$captured_args = [];

		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->andReturnUsing(
				function ( string $sql, ...$args ) use ( &$captured_sql, &$captured_args ): string {
					$captured_sql  = $sql;
					$captured_args = $args;
					return $sql;
				}
			);
		$this->wpdb->shouldReceive( 'query' )->once()->andReturn( 1 );
		$this->wpdb->insert_id = 123;

		$answer = new QuizAnswer(
			0,
			17,
			202,
			[ 'answer_id' => 'a-uuid' ],
			null,
			null,
			self::utc( '2026-04-28 10:05:00' )
		);

		$id = $this->repo->upsert( $answer );

		self::assertSame( 123, $id );
		self::assertStringContainsString( 'INSERT INTO', $captured_sql );
		self::assertStringContainsString( 'ON DUPLICATE KEY UPDATE', $captured_sql );
		self::assertStringContainsString( 'answer_data = VALUES(answer_data)', $captured_sql );
		self::assertStringContainsString( 'answered_at = VALUES(answered_at)', $captured_sql );
		self::assertSame(
			[ 17, 202, '{"answer_id":"a-uuid"}', '2026-04-28 10:05:00' ],
			$captured_args
		);
	}

	public function test_upsert_returns_existing_id_when_duplicate_update_path_taken(): void {
		// MySQL ≥5.7 default mode: insert_id stays 0 on the UPDATE branch of
		// ON DUPLICATE KEY UPDATE — repo must fall back to a SELECT lookup.
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'query' )->once()->andReturn( 2 );
		$this->wpdb->insert_id = 0;
		$this->wpdb->shouldReceive( 'get_row' )
			->once()
			->andReturn( self::row( 88, 17, 202 ) );

		$answer = new QuizAnswer(
			0,
			17,
			202,
			[ 'answer_id' => 'updated' ],
			null,
			null,
			self::utc( '2026-04-28 10:05:00' )
		);

		self::assertSame( 88, $this->repo->upsert( $answer ) );
	}

	public function test_update_scoring_writes_two_columns(): void {
		$captured_data = null;

		$this->wpdb->shouldReceive( 'update' )
			->once()
			->andReturnUsing(
				function ( string $table, array $data, array $where ) use ( &$captured_data ): int {
					$captured_data = [
						'data'  => $data,
						'where' => $where,
					];
					return 1;
				}
			);

		$ok = $this->repo->update_scoring( 5, true, 10 );

		self::assertTrue( $ok );
		self::assertSame( [ 'id' => 5 ], $captured_data['where'] );
		self::assertSame( 1, $captured_data['data']['is_correct'] );
		self::assertSame( 10, $captured_data['data']['points_awarded'] );
	}

	public function test_update_scoring_emits_zero_for_false_is_correct(): void {
		$captured_data = null;

		$this->wpdb->shouldReceive( 'update' )
			->once()
			->andReturnUsing(
				function ( string $table, array $data ) use ( &$captured_data ): int {
					$captured_data = $data;
					return 1;
				}
			);

		$this->repo->update_scoring( 5, false, 0 );

		self::assertSame( 0, $captured_data['is_correct'] );
		self::assertSame( 0, $captured_data['points_awarded'] );
	}

	public function test_update_scoring_batch_runs_inside_a_transaction(): void {
		$queries = [];

		$this->wpdb->shouldReceive( 'query' )
			->andReturnUsing(
				function ( string $sql ) use ( &$queries ): int {
					$queries[] = $sql;
					return 0;
				}
			);
		$this->wpdb->shouldReceive( 'update' )
			->times( 2 )
			->andReturn( 1 );

		$affected = $this->repo->update_scoring_batch(
			17,
			[
				5 => [
					'is_correct'     => true,
					'points_awarded' => 10,
				],
				6 => [
					'is_correct'     => false,
					'points_awarded' => 0,
				],
			]
		);

		self::assertSame( 2, $affected );
		self::assertSame( 'START TRANSACTION', $queries[0] );
		self::assertSame( 'COMMIT', $queries[ count( $queries ) - 1 ] );
	}

	public function test_update_scoring_batch_returns_zero_when_input_empty(): void {
		// No expectations on $wpdb — should short-circuit before issuing any query.
		self::assertSame( 0, $this->repo->update_scoring_batch( 17, [] ) );
	}
}
