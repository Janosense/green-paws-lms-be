<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Domain\Quiz;

use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Quiz\QuizAnswer;

final class QuizAnswerTest extends TestCase {

	/**
	 * @return array<string, mixed>
	 */
	private static function sample_row(): array {
		return [
			'id'             => '5',
			'attempt_id'     => '17',
			'question_id'    => '202',
			'answer_data'    => '{"answer_id":"a-uuid"}',
			'is_correct'     => null,
			'points_awarded' => null,
			'answered_at'    => '2026-04-28 10:05:00',
		];
	}

	public function test_constructor_assigns_every_property(): void {
		$answered = new \DateTimeImmutable( '2026-04-28 10:05:00', new \DateTimeZone( 'UTC' ) );

		$answer = new QuizAnswer(
			5,
			17,
			202,
			[ 'answer_id' => 'a-uuid' ],
			null,
			null,
			$answered
		);

		self::assertSame( 5, $answer->id );
		self::assertSame( 17, $answer->attempt_id );
		self::assertSame( 202, $answer->question_id );
		self::assertSame( [ 'answer_id' => 'a-uuid' ], $answer->answer_data );
		self::assertNull( $answer->is_correct );
		self::assertNull( $answer->points_awarded );
		self::assertSame( $answered, $answer->answered_at );
	}

	public function test_from_array_decodes_json_answer_data(): void {
		$answer = QuizAnswer::from_array( self::sample_row() );
		self::assertSame( [ 'answer_id' => 'a-uuid' ], $answer->answer_data );
	}

	public function test_from_array_preserves_null_scoring_columns(): void {
		$answer = QuizAnswer::from_array( self::sample_row() );
		self::assertNull( $answer->is_correct );
		self::assertNull( $answer->points_awarded );
	}

	public function test_from_array_decodes_scoring_when_present(): void {
		$row                   = self::sample_row();
		$row['is_correct']     = '1';
		$row['points_awarded'] = '10';

		$answer = QuizAnswer::from_array( $row );
		self::assertTrue( $answer->is_correct );
		self::assertSame( 10, $answer->points_awarded );
	}

	public function test_from_array_decodes_false_is_correct(): void {
		$row                   = self::sample_row();
		$row['is_correct']     = '0';
		$row['points_awarded'] = '0';

		$answer = QuizAnswer::from_array( $row );
		self::assertFalse( $answer->is_correct );
		self::assertSame( 0, $answer->points_awarded );
	}

	public function test_round_trip_preserves_payload_through_to_array(): void {
		$row     = self::sample_row();
		$answer  = QuizAnswer::from_array( $row );
		$rebuilt = QuizAnswer::from_array( $answer->to_array() );

		self::assertSame( $answer->id, $rebuilt->id );
		self::assertSame( $answer->attempt_id, $rebuilt->attempt_id );
		self::assertSame( $answer->question_id, $rebuilt->question_id );
		self::assertSame( $answer->answer_data, $rebuilt->answer_data );
	}

	public function test_to_array_encodes_answer_data_as_json(): void {
		$answer = new QuizAnswer(
			5,
			17,
			202,
			[ 'answer_ids' => [ 'a', 'b', 'c' ] ],
			null,
			null,
			new \DateTimeImmutable( '2026-04-28 10:05:00', new \DateTimeZone( 'UTC' ) )
		);

		$out = $answer->to_array();
		self::assertSame( '{"answer_ids":["a","b","c"]}', $out['answer_data'] );
	}

	public function test_to_array_emits_is_correct_as_int(): void {
		$answer = new QuizAnswer(
			5,
			17,
			202,
			[ 'value' => true ],
			true,
			10,
			new \DateTimeImmutable( '2026-04-28 10:05:00', new \DateTimeZone( 'UTC' ) )
		);

		$out = $answer->to_array();
		self::assertSame( 1, $out['is_correct'] );
		self::assertSame( 10, $out['points_awarded'] );
	}
}
