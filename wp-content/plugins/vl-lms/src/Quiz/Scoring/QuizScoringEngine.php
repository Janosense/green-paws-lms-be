<?php

declare(strict_types=1);

namespace VL\LMS\Quiz\Scoring;

use VL\LMS\Domain\Quiz\QuestionType;
use VL\LMS\Domain\Quiz\QuizAnswer;
use WP_Post;

/**
 * Dispatches scoring across an attempt's questions and answers.
 *
 * Reads `_vl_question_type` from each question and routes to the
 * matching per-type scorer. Unanswered questions (no `QuizAnswer` row
 * for the question id) score zero with `is_correct=false` — they are
 * not silently skipped, so the totalling pass downstream sees the full
 * question set.
 *
 * @author Tymofii Synianskyi
 */
class QuizScoringEngine {

	public function __construct(
		private readonly SingleChoiceScorer $single_choice,
		private readonly MultipleChoiceScorer $multiple_choice,
		private readonly TrueFalseScorer $true_false,
		private readonly TextScorer $text
	) {
	}

	/**
	 * @param list<WP_Post>  $questions
	 * @param list<QuizAnswer> $answers
	 *
	 * @return array<int, ScoringResult>
	 */
	public function score_attempt( array $questions, array $answers ): array {
		$by_question = [];
		foreach ( $answers as $answer ) {
			$by_question[ $answer->question_id ] = $answer;
		}

		$out = [];
		foreach ( $questions as $question ) {
			$qid    = (int) $question->ID;
			$answer = $by_question[ $qid ] ?? null;
			$type   = QuestionType::from_meta_value( $this->question_type_meta( $qid ) );

			$out[ $qid ] = match ( $type ) {
				QuestionType::SINGLE_CHOICE   => $this->single_choice->score( $question, $answer ),
				QuestionType::MULTIPLE_CHOICE => $this->multiple_choice->score( $question, $answer ),
				QuestionType::TRUE_FALSE      => $this->true_false->score( $question, $answer ),
				QuestionType::TEXT            => $this->text->score( $question, $answer ),
			};
		}
		return $out;
	}

	private function question_type_meta( int $question_id ): string {
		$value = get_post_meta( $question_id, '_vl_question_type', true );
		if ( is_string( $value ) ) {
			return $value;
		}
		return '';
	}
}
