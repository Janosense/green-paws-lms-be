<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Fixtures;

use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Quiz\QuizAnswer;
use VL\LMS\Tests\Fixtures\InMemoryQuizAnswerRepository;

final class InMemoryQuizAnswerRepositoryTest extends TestCase {

	private static function utc( string $value ): \DateTimeImmutable {
		return new \DateTimeImmutable( $value, new \DateTimeZone( 'UTC' ) );
	}

	private static function answer(
		int $attempt_id = 17,
		int $question_id = 202,
		array $payload = [ 'answer_id' => 'a-uuid' ]
	): QuizAnswer {
		return new QuizAnswer(
			0,
			$attempt_id,
			$question_id,
			$payload,
			null,
			null,
			self::utc( '2026-04-28 10:05:00' )
		);
	}

	public function test_upsert_inserts_when_no_existing_pair(): void {
		$repo = new InMemoryQuizAnswerRepository();
		$id   = $repo->upsert( self::answer() );

		self::assertSame( 1, $id );
		$found = $repo->find( 1 );
		self::assertNotNull( $found );
		self::assertSame( 17, $found->attempt_id );
		self::assertSame( 202, $found->question_id );
	}

	public function test_upsert_updates_existing_pair_in_place(): void {
		$repo = new InMemoryQuizAnswerRepository();
		$id   = $repo->upsert( self::answer( 17, 202, [ 'answer_id' => 'first' ] ) );
		$id2  = $repo->upsert( self::answer( 17, 202, [ 'answer_id' => 'second' ] ) );

		self::assertSame( $id, $id2 );
		$found = $repo->find( $id );
		self::assertNotNull( $found );
		self::assertSame( 'second', $found->answer_data['answer_id'] );
	}

	public function test_upsert_does_not_collide_across_different_attempts(): void {
		$repo = new InMemoryQuizAnswerRepository();
		$id_a = $repo->upsert( self::answer( 17, 202 ) );
		$id_b = $repo->upsert( self::answer( 18, 202 ) );

		self::assertNotSame( $id_a, $id_b );
		self::assertCount( 1, $repo->list_for_attempt( 17 ) );
		self::assertCount( 1, $repo->list_for_attempt( 18 ) );
	}

	public function test_find_by_attempt_and_question_returns_match(): void {
		$repo = new InMemoryQuizAnswerRepository();
		$repo->upsert( self::answer( 17, 202 ) );
		$repo->upsert( self::answer( 17, 203 ) );

		$found = $repo->find_by_attempt_and_question( 17, 203 );
		self::assertNotNull( $found );
		self::assertSame( 203, $found->question_id );
	}

	public function test_list_for_attempt_orders_by_id(): void {
		$repo = new InMemoryQuizAnswerRepository();
		$repo->upsert( self::answer( 17, 202 ) );
		$repo->upsert( self::answer( 17, 203 ) );
		$repo->upsert( self::answer( 17, 204 ) );

		$rows = $repo->list_for_attempt( 17 );
		self::assertCount( 3, $rows );
		self::assertSame( 202, $rows[0]->question_id );
		self::assertSame( 204, $rows[2]->question_id );
	}

	public function test_update_scoring_writes_immutably(): void {
		$repo = new InMemoryQuizAnswerRepository();
		$id   = $repo->upsert( self::answer() );

		self::assertTrue( $repo->update_scoring( $id, true, 10 ) );
		$row = $repo->find( $id );
		self::assertNotNull( $row );
		self::assertTrue( $row->is_correct );
		self::assertSame( 10, $row->points_awarded );
	}

	public function test_update_scoring_batch_writes_each_row(): void {
		$repo = new InMemoryQuizAnswerRepository();
		$id_a = $repo->upsert( self::answer( 17, 202 ) );
		$id_b = $repo->upsert( self::answer( 17, 203 ) );

		$affected = $repo->update_scoring_batch(
			17,
			[
				$id_a => [
					'is_correct'     => true,
					'points_awarded' => 10,
				],
				$id_b => [
					'is_correct'     => false,
					'points_awarded' => 0,
				],
			]
		);

		self::assertSame( 2, $affected );
		self::assertTrue( $repo->find( $id_a )?->is_correct );
		self::assertFalse( $repo->find( $id_b )?->is_correct );
	}

	public function test_update_scoring_returns_false_for_unknown_id(): void {
		$repo = new InMemoryQuizAnswerRepository();
		self::assertFalse( $repo->update_scoring( 999, true, 10 ) );
	}
}
