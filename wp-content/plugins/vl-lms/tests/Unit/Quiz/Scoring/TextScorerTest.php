<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Quiz\Scoring;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Quiz\QuizAnswer;
use VL\LMS\Quiz\Scoring\TextScorer;
use VL\LMS\Support\Logger;
use WP_Post;

final class TextScorerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private TextScorer $scorer;

	/** @var Mockery\MockInterface&Logger */
	private $logger;

	/** @var array<int, array<string, mixed>> */
	private array $meta = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->logger = Mockery::mock( Logger::class );
		$this->scorer = new TextScorer( $this->logger );
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

	private function seed( int $id, string $correct_text, string $mode, int $points = 5 ): void {
		$this->meta[ $id ] = [
			'_vl_question_points'       => (string) $points,
			'_vl_question_correct_text' => $correct_text,
			'_vl_question_match_mode'   => $mode,
		];
	}

	private function answer( string $text ): QuizAnswer {
		return new QuizAnswer(
			1,
			17,
			201,
			[ 'text' => $text ],
			null,
			null,
			new \DateTimeImmutable( '2026-04-29 10:00:00', new \DateTimeZone( 'UTC' ) )
		);
	}

	public function test_exact_mode_strict_match(): void {
		$this->seed( 201, 'fluffy', 'exact' );

		$ok  = $this->scorer->score( $this->question(), $this->answer( 'fluffy' ) );
		$bad = $this->scorer->score( $this->question(), $this->answer( 'Fluffy' ) );

		self::assertTrue( $ok->is_correct );
		self::assertSame( 5, $ok->points_awarded );
		self::assertFalse( $bad->is_correct );
	}

	public function test_case_insensitive_mode_lowers_and_trims(): void {
		$this->seed( 201, '  Fluffy ', 'case_insensitive' );

		$result = $this->scorer->score( $this->question(), $this->answer( 'fluffy' ) );

		self::assertTrue( $result->is_correct );
	}

	public function test_regex_mode_matches_pattern(): void {
		$this->seed( 201, '^cat[a-z]*$', 'regex' );

		$result = $this->scorer->score( $this->question(), $this->answer( 'caterpillar' ) );

		self::assertTrue( $result->is_correct );
		self::assertSame( 5, $result->points_awarded );
	}

	public function test_regex_mode_rejects_non_match(): void {
		$this->seed( 201, '^cat$', 'regex' );

		$result = $this->scorer->score( $this->question(), $this->answer( 'dog' ) );

		self::assertFalse( $result->is_correct );
	}

	public function test_regex_compilation_failure_returns_zero_and_logs(): void {
		$this->seed( 201, '(unclosed', 'regex' );
		$this->logger->shouldReceive( 'warning' )->once();

		$result = $this->scorer->score( $this->question(), $this->answer( 'anything' ) );

		self::assertFalse( $result->is_correct );
		self::assertSame( 0, $result->points_awarded );
	}

	public function test_unanswered_returns_zero(): void {
		$this->seed( 201, 'fluffy', 'exact' );

		$result = $this->scorer->score( $this->question(), null );
		self::assertFalse( $result->is_correct );
	}

	public function test_non_string_text_returns_zero(): void {
		$this->seed( 201, 'fluffy', 'exact' );
		$bad = new QuizAnswer(
			1,
			17,
			201,
			[ 'text' => 42 ],
			null,
			null,
			new \DateTimeImmutable( '2026-04-29 10:00:00', new \DateTimeZone( 'UTC' ) )
		);

		$result = $this->scorer->score( $this->question(), $bad );
		self::assertFalse( $result->is_correct );
	}

	public function test_empty_correct_text_returns_zero(): void {
		$this->seed( 201, '', 'exact' );

		$result = $this->scorer->score( $this->question(), $this->answer( 'whatever' ) );
		self::assertFalse( $result->is_correct );
	}

	public function test_unknown_match_mode_falls_back_to_exact(): void {
		$this->seed( 201, 'fluffy', 'fuzzy' );

		$ok  = $this->scorer->score( $this->question(), $this->answer( 'fluffy' ) );
		$bad = $this->scorer->score( $this->question(), $this->answer( 'Fluffy' ) );

		self::assertTrue( $ok->is_correct );
		self::assertFalse( $bad->is_correct );
	}
}
