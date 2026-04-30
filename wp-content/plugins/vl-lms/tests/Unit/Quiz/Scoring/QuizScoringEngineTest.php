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
use VL\LMS\Quiz\Scoring\QuizScoringEngine;
use VL\LMS\Quiz\Scoring\ScoringResult;
use VL\LMS\Quiz\Scoring\SingleChoiceScorer;
use VL\LMS\Quiz\Scoring\TextScorer;
use VL\LMS\Quiz\Scoring\TrueFalseScorer;
use WP_Post;

final class QuizScoringEngineTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface&SingleChoiceScorer */
	private $sc;

	/** @var Mockery\MockInterface&MultipleChoiceScorer */
	private $mc;

	/** @var Mockery\MockInterface&TrueFalseScorer */
	private $tf;

	/** @var Mockery\MockInterface&TextScorer */
	private $tx;

	/** @var array<int, string> */
	private array $type_meta = [];

	private QuizScoringEngine $engine;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->sc = Mockery::mock( SingleChoiceScorer::class );
		$this->mc = Mockery::mock( MultipleChoiceScorer::class );
		$this->tf = Mockery::mock( TrueFalseScorer::class );
		$this->tx = Mockery::mock( TextScorer::class );

		$this->engine = new QuizScoringEngine( $this->sc, $this->mc, $this->tf, $this->tx );

		$this->type_meta = [];

		$ref = &$this->type_meta;
		Functions\when( 'get_post_meta' )->alias(
			static function ( int $id, string $key, bool $single = false ) use ( &$ref ): string {
				if ( '_vl_question_type' === $key ) {
					return $ref[ $id ] ?? '';
				}
				return '';
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

	private function answer( int $question_id ): QuizAnswer {
		return new QuizAnswer(
			$question_id,
			17,
			$question_id,
			[],
			null,
			null,
			new \DateTimeImmutable( '2026-04-29 10:00:00', new \DateTimeZone( 'UTC' ) )
		);
	}

	public function test_dispatches_each_question_to_correct_scorer(): void {
		$this->type_meta = [
			201 => 'single_choice',
			202 => 'multiple_choice',
			203 => 'true_false',
			204 => 'text',
		];

		$result = new ScoringResult( true, 1 );
		$this->sc->shouldReceive( 'score' )->once()->andReturn( $result );
		$this->mc->shouldReceive( 'score' )->once()->andReturn( $result );
		$this->tf->shouldReceive( 'score' )->once()->andReturn( $result );
		$this->tx->shouldReceive( 'score' )->once()->andReturn( $result );

		$out = $this->engine->score_attempt(
			[ $this->question( 201 ), $this->question( 202 ), $this->question( 203 ), $this->question( 204 ) ],
			[ $this->answer( 201 ), $this->answer( 202 ), $this->answer( 203 ), $this->answer( 204 ) ]
		);

		self::assertCount( 4, $out );
		self::assertArrayHasKey( 201, $out );
		self::assertArrayHasKey( 204, $out );
	}

	public function test_unanswered_question_passes_null_answer_to_scorer(): void {
		$this->type_meta[201] = 'single_choice';

		$captured_answer = 'sentinel';
		$this->sc->shouldReceive( 'score' )
			->once()
			->andReturnUsing(
				function ( WP_Post $q, ?QuizAnswer $a ) use ( &$captured_answer ): ScoringResult {
					$captured_answer = $a;
					return new ScoringResult( false, 0 );
				}
			);

		$out = $this->engine->score_attempt( [ $this->question( 201 ) ], [] );

		self::assertNull( $captured_answer );
		self::assertArrayHasKey( 201, $out );
		self::assertFalse( $out[201]->is_correct );
		self::assertSame( 0, $out[201]->points_awarded );
	}

	public function test_unknown_question_type_falls_back_to_single_choice(): void {
		$this->type_meta[201] = 'wat';

		$this->sc->shouldReceive( 'score' )
			->once()
			->andReturn( new ScoringResult( false, 0 ) );

		$out = $this->engine->score_attempt( [ $this->question( 201 ) ], [] );

		self::assertArrayHasKey( 201, $out );
	}
}
