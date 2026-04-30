<?php

declare(strict_types=1);

namespace VL\LMS\Quiz\Scoring;

use VL\LMS\Domain\Quiz\QuizAnswer;
use WP_Post;

/**
 * Scorer for `single_choice` questions.
 *
 * Reads the question's `_vl_question_answers` array, finds the entry
 * with `is_correct=true`, and compares its `id` to
 * `answer.answer_data['answer_id']`. Match → full points; mismatch (or
 * unanswered) → zero.
 *
 * @author Tymofii Synianskyi
 */
class SingleChoiceScorer {

	public function score( WP_Post $question, ?QuizAnswer $answer ): ScoringResult {
		$points = $this->read_points( (int) $question->ID );

		if ( null === $answer ) {
			return new ScoringResult( false, 0 );
		}

		$selected = $answer->answer_data['answer_id'] ?? null;
		if ( ! is_string( $selected ) || '' === $selected ) {
			return new ScoringResult( false, 0 );
		}

		$correct_id = $this->find_correct_answer_id( (int) $question->ID );
		if ( null === $correct_id ) {
			return new ScoringResult( false, 0 );
		}

		$is_correct = ( $selected === $correct_id );
		return new ScoringResult( $is_correct, $is_correct ? $points : 0 );
	}

	private function find_correct_answer_id( int $question_id ): ?string {
		$answers = get_post_meta( $question_id, '_vl_question_answers', true );
		if ( ! is_array( $answers ) ) {
			return null;
		}
		foreach ( $answers as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			if ( ! empty( $entry['is_correct'] ) && isset( $entry['id'] ) ) {
				return (string) $entry['id'];
			}
		}
		return null;
	}

	private function read_points( int $question_id ): int {
		$raw = get_post_meta( $question_id, '_vl_question_points', true );
		if ( is_string( $raw ) || is_int( $raw ) ) {
			$value = (int) $raw;
			return $value > 0 ? $value : 0;
		}
		return 0;
	}
}
