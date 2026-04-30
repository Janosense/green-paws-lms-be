<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Quiz\Scoring;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Quiz\QuizAnswer;
use VL\LMS\Quiz\Scoring\MultipleChoiceScorer;
use WP_Post;

final class MultipleChoiceScorerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private MultipleChoiceScorer $scorer;

	/** @var array<int, array<string, mixed>> */
	private array $meta = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->scorer = new MultipleChoiceScorer();
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

	private function question( int $id ): WP_Post {
		$post     = Mockery::mock( 'WP_Post' );
		$post->ID = $id;
		assert( $post instanceof WP_Post );
		return $post;
	}

	private function seed_question( int $id, int $points = 4 ): void {
		$this->meta[ $id ] = [
			'_vl_question_points'  => (string) $points,
			'_vl_question_answers' => [
				[
					'id'         => 'a',
					'is_correct' => true,
				],
				[
					'id'         => 'b',
					'is_correct' => true,
				],
				[
					'id'         => 'c',
					'is_correct' => false,
				],
				[
					'id'         => 'd',
					'is_correct' => false,
				],
			],
		];
	}

	private function answer( array $ids ): QuizAnswer {
		return new QuizAnswer(
			1,
			17,
			201,
			[ 'answer_ids' => $ids ],
			null,
			null,
			new \DateTimeImmutable( '2026-04-29 10:00:00', new \DateTimeZone( 'UTC' ) )
		);
	}

	public function test_full_correct_set_returns_full_points(): void {
		$this->seed_question( 201 );

		$result = $this->scorer->score( $this->question( 201 ), $this->answer( [ 'a', 'b' ] ) );

		self::assertTrue( $result->is_correct );
		self::assertSame( 4, $result->points_awarded );
	}

	public function test_set_order_does_not_matter(): void {
		$this->seed_question( 201 );

		$result = $this->scorer->score( $this->question( 201 ), $this->answer( [ 'b', 'a' ] ) );

		self::assertTrue( $result->is_correct );
	}

	public function test_missing_correct_returns_zero(): void {
		$this->seed_question( 201 );

		$result = $this->scorer->score( $this->question( 201 ), $this->answer( [ 'a' ] ) );

		self::assertFalse( $result->is_correct );
		self::assertSame( 0, $result->points_awarded );
	}

	public function test_extra_incorrect_returns_zero(): void {
		$this->seed_question( 201 );

		$result = $this->scorer->score( $this->question( 201 ), $this->answer( [ 'a', 'b', 'c' ] ) );

		self::assertFalse( $result->is_correct );
		self::assertSame( 0, $result->points_awarded );
	}

	public function test_duplicate_ids_normalize_to_set(): void {
		$this->seed_question( 201 );

		$result = $this->scorer->score( $this->question( 201 ), $this->answer( [ 'a', 'b', 'a' ] ) );

		self::assertTrue( $result->is_correct );
	}

	public function test_unanswered_returns_zero(): void {
		$this->seed_question( 201 );

		$result = $this->scorer->score( $this->question( 201 ), null );

		self::assertFalse( $result->is_correct );
	}

	public function test_non_array_answer_ids_returns_zero(): void {
		$this->seed_question( 201 );
		$bad = new QuizAnswer(
			1,
			17,
			201,
			[ 'answer_ids' => 'not-an-array' ],
			null,
			null,
			new \DateTimeImmutable( '2026-04-29 10:00:00', new \DateTimeZone( 'UTC' ) )
		);

		$result = $this->scorer->score( $this->question( 201 ), $bad );
		self::assertFalse( $result->is_correct );
	}

	public function test_no_correct_in_meta_returns_zero(): void {
		$this->meta[201] = [
			'_vl_question_points'  => '4',
			'_vl_question_answers' => [
				[
					'id'         => 'a',
					'is_correct' => false,
				],
			],
		];

		$result = $this->scorer->score( $this->question( 201 ), $this->answer( [ 'a' ] ) );
		self::assertFalse( $result->is_correct );
	}
}
