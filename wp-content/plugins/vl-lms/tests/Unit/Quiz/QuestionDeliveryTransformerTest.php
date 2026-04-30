<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Quiz;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Quiz\QuizAttemptStatus;
use VL\LMS\Domain\Quiz\ShowCorrectAnswersPolicy;
use VL\LMS\Quiz\QuestionDeliveryTransformer;
use WP_Post;

final class QuestionDeliveryTransformerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private QuestionDeliveryTransformer $transformer;

	/** @var array<int, array<string, mixed>> */
	private array $meta = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->transformer = new QuestionDeliveryTransformer();
		$this->meta        = [];

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

	private function set_meta( int $id, string $key, mixed $value ): void {
		$this->meta[ $id ][ $key ] = $value;
	}

	private function question( int $id, string $title = 'Q' ): WP_Post {
		$post             = Mockery::mock( 'WP_Post' );
		$post->ID         = $id;
		$post->post_title = $title;
		$post->post_type  = 'vl_quiz_question';
		assert( $post instanceof WP_Post );
		return $post;
	}

	private function seed_single_choice( int $id, string $title = 'Pick one' ): void {
		$this->set_meta( $id, '_vl_question_type', 'single_choice' );
		$this->set_meta( $id, '_vl_question_points', '2' );
		$this->set_meta(
			$id,
			'_vl_question_answers',
			[
				[
					'id'         => 'a',
					'text'       => 'A',
					'is_correct' => false,
				],
				[
					'id'         => 'b',
					'text'       => 'B',
					'is_correct' => true,
				],
				[
					'id'         => 'c',
					'text'       => 'C',
					'is_correct' => false,
				],
			]
		);
		$this->set_meta( $id, '_vl_question_explanation', 'Because B.' );
	}

	private function seed_text( int $id ): void {
		$this->set_meta( $id, '_vl_question_type', 'text' );
		$this->set_meta( $id, '_vl_question_points', '3' );
		$this->set_meta( $id, '_vl_question_correct_text', 'fluffy' );
		$this->set_meta( $id, '_vl_question_match_mode', 'case_insensitive' );
		$this->set_meta( $id, '_vl_question_explanation', 'See manual.' );
	}

	public function test_in_progress_with_after_submit_hides_reveal_fields(): void {
		$this->seed_single_choice( 201 );

		$out = $this->transformer->deliver_for_attempt_view(
			[ $this->question( 201 ) ],
			ShowCorrectAnswersPolicy::AFTER_SUBMIT,
			QuizAttemptStatus::IN_PROGRESS,
			null
		);

		self::assertCount( 1, $out );
		self::assertSame( 'single_choice', $out[0]['type'] );
		self::assertSame( 2, $out[0]['points'] );
		self::assertCount( 3, $out[0]['answers'] );
		self::assertArrayNotHasKey( 'is_correct', $out[0]['answers'][0] );
		self::assertArrayNotHasKey( 'explanation', $out[0] );
	}

	public function test_submitted_with_after_submit_reveals_correct_answers(): void {
		$this->seed_single_choice( 201 );

		$out = $this->transformer->deliver_for_attempt_view(
			[ $this->question( 201 ) ],
			ShowCorrectAnswersPolicy::AFTER_SUBMIT,
			QuizAttemptStatus::SUBMITTED,
			false
		);

		self::assertTrue( $out[0]['answers'][1]['is_correct'] );
		self::assertFalse( $out[0]['answers'][0]['is_correct'] );
		self::assertSame( 'Because B.', $out[0]['explanation'] );
	}

	public function test_expired_with_after_submit_reveals_answers(): void {
		$this->seed_single_choice( 201 );

		$out = $this->transformer->deliver_for_attempt_view(
			[ $this->question( 201 ) ],
			ShowCorrectAnswersPolicy::AFTER_SUBMIT,
			QuizAttemptStatus::EXPIRED,
			null
		);

		self::assertArrayHasKey( 'is_correct', $out[0]['answers'][0] );
	}

	public function test_after_pass_with_failing_attempt_keeps_hidden(): void {
		$this->seed_single_choice( 201 );

		$out = $this->transformer->deliver_for_attempt_view(
			[ $this->question( 201 ) ],
			ShowCorrectAnswersPolicy::AFTER_PASS,
			QuizAttemptStatus::SUBMITTED,
			false
		);

		self::assertArrayNotHasKey( 'is_correct', $out[0]['answers'][0] );
		self::assertArrayNotHasKey( 'explanation', $out[0] );
	}

	public function test_after_pass_with_null_passed_keeps_hidden(): void {
		$this->seed_single_choice( 201 );

		$out = $this->transformer->deliver_for_attempt_view(
			[ $this->question( 201 ) ],
			ShowCorrectAnswersPolicy::AFTER_PASS,
			QuizAttemptStatus::SUBMITTED,
			null
		);

		self::assertArrayNotHasKey( 'is_correct', $out[0]['answers'][0] );
	}

	public function test_after_pass_with_passing_attempt_reveals_answers(): void {
		$this->seed_single_choice( 201 );

		$out = $this->transformer->deliver_for_attempt_view(
			[ $this->question( 201 ) ],
			ShowCorrectAnswersPolicy::AFTER_PASS,
			QuizAttemptStatus::SUBMITTED,
			true
		);

		self::assertTrue( $out[0]['answers'][1]['is_correct'] );
		self::assertSame( 'Because B.', $out[0]['explanation'] );
	}

	public function test_after_pass_with_expired_does_not_reveal(): void {
		$this->seed_single_choice( 201 );

		// Even with passed=true, EXPIRED attempts under AFTER_PASS keep
		// markers hidden — the reveal trigger requires SUBMITTED status.
		$out = $this->transformer->deliver_for_attempt_view(
			[ $this->question( 201 ) ],
			ShowCorrectAnswersPolicy::AFTER_PASS,
			QuizAttemptStatus::EXPIRED,
			true
		);

		self::assertArrayNotHasKey( 'is_correct', $out[0]['answers'][0] );
	}

	public function test_never_policy_hides_even_after_passing_submit(): void {
		$this->seed_single_choice( 201 );

		$out = $this->transformer->deliver_for_attempt_view(
			[ $this->question( 201 ) ],
			ShowCorrectAnswersPolicy::NEVER,
			QuizAttemptStatus::SUBMITTED,
			true
		);

		self::assertArrayNotHasKey( 'is_correct', $out[0]['answers'][0] );
		self::assertArrayNotHasKey( 'explanation', $out[0] );
	}

	public function test_text_question_in_progress_omits_correct_text_and_match_mode(): void {
		$this->seed_text( 202 );

		$out = $this->transformer->deliver_for_attempt_view(
			[ $this->question( 202 ) ],
			ShowCorrectAnswersPolicy::AFTER_SUBMIT,
			QuizAttemptStatus::IN_PROGRESS,
			null
		);

		self::assertArrayNotHasKey( 'correct_text', $out[0] );
		self::assertArrayNotHasKey( 'match_mode', $out[0] );
		self::assertArrayNotHasKey( 'answers', $out[0] );
	}

	public function test_text_question_after_submit_includes_correct_text_and_match_mode(): void {
		$this->seed_text( 202 );

		$out = $this->transformer->deliver_for_attempt_view(
			[ $this->question( 202 ) ],
			ShowCorrectAnswersPolicy::AFTER_SUBMIT,
			QuizAttemptStatus::SUBMITTED,
			false
		);

		self::assertSame( 'fluffy', $out[0]['correct_text'] );
		self::assertSame( 'case_insensitive', $out[0]['match_mode'] );
		self::assertSame( 'See manual.', $out[0]['explanation'] );
	}

	public function test_unknown_question_type_falls_back_to_single_choice(): void {
		$this->set_meta( 203, '_vl_question_type', 'wat' );
		$this->set_meta( 203, '_vl_question_points', '1' );
		$this->set_meta( 203, '_vl_question_answers', [] );

		$out = $this->transformer->deliver_for_attempt_view(
			[ $this->question( 203 ) ],
			ShowCorrectAnswersPolicy::AFTER_SUBMIT,
			QuizAttemptStatus::IN_PROGRESS,
			null
		);

		self::assertSame( 'single_choice', $out[0]['type'] );
	}

	public function test_question_with_no_answers_meta_returns_empty_answers_array(): void {
		$this->set_meta( 204, '_vl_question_type', 'multiple_choice' );
		$this->set_meta( 204, '_vl_question_points', '5' );

		$out = $this->transformer->deliver_for_attempt_view(
			[ $this->question( 204 ) ],
			ShowCorrectAnswersPolicy::AFTER_SUBMIT,
			QuizAttemptStatus::IN_PROGRESS,
			null
		);

		self::assertSame( [], $out[0]['answers'] );
	}
}
