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
}
