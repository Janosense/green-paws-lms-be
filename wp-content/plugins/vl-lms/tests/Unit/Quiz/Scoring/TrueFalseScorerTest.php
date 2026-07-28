<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Quiz\Scoring;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Quiz\QuizAnswer;
use VL\LMS\Quiz\Scoring\TrueFalseScorer;
use WP_Post;

final class TrueFalseScorerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private TrueFalseScorer $scorer;

	/** @var array<int, array<string, mixed>> */
	private array $meta = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->scorer = new TrueFalseScorer();
		$this->meta   = [];

		$meta = &$this->meta;
		Functions\when( 'get_post_meta' )->alias(
			static function ( int $id, string $key, bool $single = false ) use ( &$meta ): mixed {
				return $meta[ $id ][ $key ] ?? '';
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function question( int $id = 201 ): WP_Post {
		$post     = Mockery::mock( 'WP_Post' );
		$post->ID = $id;
		assert( $post instanceof WP_Post );
		return $post;
	}

	private function seed( int $id, string $correct_text, int $points = 1 ): void {
		$this->meta[ $id ] = [
			'_vl_question_points'  => (string) $points,
			'_vl_question_answers' => [
				[
					'id'         => 't',
					'text'       => 'true',
					'is_correct' => 'true' === $correct_text,
				],
				[
					'id'         => 'f',
					'text'       => 'false',
					'is_correct' => 'false' === $correct_text,
				],
			],
		];
	}

	private function answer( bool $value ): QuizAnswer {
		return new QuizAnswer(
			1,
			17,
			201,
			[ 'value' => $value ],
			null,
			null,
			new \DateTimeImmutable( '2026-04-29 10:00:00', new \DateTimeZone( 'UTC' ) )
		);
	}

	public function test_correct_true_value_returns_full_points(): void {
		$this->seed( 201, 'true' );

		$result = $this->scorer->score( $this->question(), $this->answer( true ) );
		self::assertTrue( $result->is_correct );
		self::assertSame( 1, $result->points_awarded );
	}

	public function test_correct_false_value_returns_full_points(): void {
		$this->seed( 201, 'false' );

		$result = $this->scorer->score( $this->question(), $this->answer( false ) );
		self::assertTrue( $result->is_correct );
	}

	public function test_wrong_value_returns_zero(): void {
		$this->seed( 201, 'true' );

		$result = $this->scorer->score( $this->question(), $this->answer( false ) );
		self::assertFalse( $result->is_correct );
	}

	public function test_unanswered_returns_zero(): void {
		$this->seed( 201, 'true' );

		$result = $this->scorer->score( $this->question(), null );
		self::assertFalse( $result->is_correct );
	}

	public function test_string_value_instead_of_bool_returns_zero(): void {
		$this->seed( 201, 'true' );
		$bad = new QuizAnswer(
			1,
			17,
			201,
			[ 'value' => 'true' ],
			null,
			null,
			new \DateTimeImmutable( '2026-04-29 10:00:00', new \DateTimeZone( 'UTC' ) )
		);

		$result = $this->scorer->score( $this->question(), $bad );
		self::assertFalse( $result->is_correct );
	}

	/**
	 * The answers builder in wp-admin is a free-text list, so real questions
	 * carry human labels ("Правда / Неправда", and `Так` / `Ні` in the demo
	 * seeder) rather than the canonical `true` / `false` strings. Matching on
	 * text alone left the expected value unresolvable and scored every such
	 * question zero — including correct answers. Slot order decides instead.
	 *
	 * @param list<array<string, mixed>> $rows
	 */
	private function seed_rows( int $id, array $rows, int $points = 1 ): void {
		$this->meta[ $id ] = [
			'_vl_question_points'  => (string) $points,
			'_vl_question_answers' => $rows,
		];
	}

	public function test_non_canonical_labels_score_by_position_true_side(): void {
		$this->seed_rows(
			201,
			[
				[
					'id'         => 'c1',
					'text'       => 'Так',
					'is_correct' => true,
				],
				[
					'id'         => 'c2',
					'text'       => 'Ні',
					'is_correct' => false,
				],
			]
		);

		$hit = $this->scorer->score( $this->question(), $this->answer( true ) );
		self::assertTrue( $hit->is_correct );
		self::assertSame( 1, $hit->points_awarded );

		$miss = $this->scorer->score( $this->question(), $this->answer( false ) );
		self::assertFalse( $miss->is_correct );
		self::assertSame( 0, $miss->points_awarded );
	}

	public function test_non_canonical_labels_score_by_position_false_side(): void {
		$this->seed_rows(
			201,
			[
				[
					'id'         => 'c1',
					'text'       => 'Правда',
					'is_correct' => false,
				],
				[
					'id'         => 'c2',
					'text'       => 'Неправда',
					'is_correct' => true,
				],
			],
			3
		);

		$hit = $this->scorer->score( $this->question(), $this->answer( false ) );
		self::assertTrue( $hit->is_correct );
		self::assertSame( 3, $hit->points_awarded );

		self::assertFalse( $this->scorer->score( $this->question(), $this->answer( true ) )->is_correct );
	}

	public function test_canonical_text_outranks_position_when_list_is_reordered(): void {
		$this->seed_rows(
			201,
			[
				[
					'id'         => 'f',
					'text'       => 'False',
					'is_correct' => true,
				],
				[
					'id'         => 't',
					'text'       => 'true',
					'is_correct' => false,
				],
			]
		);

		self::assertTrue( $this->scorer->score( $this->question(), $this->answer( false ) )->is_correct );
		self::assertFalse( $this->scorer->score( $this->question(), $this->answer( true ) )->is_correct );
	}

	public function test_malformed_rows_do_not_shift_positional_slots(): void {
		// QuestionDeliveryTransformer drops non-array rows before delivery, so
		// the player's slot 1 is the "Ні" entry — the scorer must agree.
		$this->seed_rows(
			201,
			[
				'garbage',
				[
					'id'         => 'c1',
					'text'       => 'Так',
					'is_correct' => false,
				],
				[
					'id'         => 'c2',
					'text'       => 'Ні',
					'is_correct' => true,
				],
			]
		);

		self::assertTrue( $this->scorer->score( $this->question(), $this->answer( false ) )->is_correct );
	}

	public function test_correct_entry_past_the_second_slot_scores_zero(): void {
		// Nothing beyond slot 1 maps to a bool — the player renders two
		// choices, so neither answer can be the flagged one.
		$this->seed_rows(
			201,
			[
				[
					'id'         => 'a',
					'text'       => 'Так',
					'is_correct' => false,
				],
				[
					'id'         => 'b',
					'text'       => 'Ні',
					'is_correct' => false,
				],
				[
					'id'         => 'c',
					'text'       => 'Можливо',
					'is_correct' => true,
				],
			]
		);

		self::assertFalse( $this->scorer->score( $this->question(), $this->answer( true ) )->is_correct );
		self::assertFalse( $this->scorer->score( $this->question(), $this->answer( false ) )->is_correct );
	}

	public function test_no_flagged_answer_scores_zero(): void {
		$this->seed_rows(
			201,
			[
				[
					'id'         => 'c1',
					'text'       => 'Так',
					'is_correct' => false,
				],
				[
					'id'         => 'c2',
					'text'       => 'Ні',
					'is_correct' => false,
				],
			]
		);

		self::assertFalse( $this->scorer->score( $this->question(), $this->answer( true ) )->is_correct );
		self::assertFalse( $this->scorer->score( $this->question(), $this->answer( false ) )->is_correct );
	}
}
