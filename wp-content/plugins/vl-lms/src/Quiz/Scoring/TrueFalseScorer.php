<?php

declare(strict_types=1);

namespace VL\LMS\Quiz\Scoring;

use VL\LMS\Domain\Quiz\QuizAnswer;
use WP_Post;

/**
 * Scorer for `true_false` questions.
 *
 * The question stores its answers in `_vl_question_answers` in the same
 * shape as choice questions, with the entry flagged `is_correct=true`
 * carrying the canonical answer text — `"true"` or `"false"`. The
 * submitted answer arrives as a real PHP `bool` in
 * `answer.answer_data['value']`; that bool is converted to the same
 * string and compared. Match → full points; mismatch → zero.
 *
 * @author Tymofii Synianskyi
 */
class TrueFalseScorer {

	public function score( WP_Post $question, ?QuizAnswer $answer ): ScoringResult {
		$points = $this->read_points( (int) $question->ID );

		if ( null === $answer ) {
			return new ScoringResult( false, 0 );
		}

		$submitted = $answer->answer_data['value'] ?? null;
		if ( ! is_bool( $submitted ) ) {
			return new ScoringResult( false, 0 );
		}

		$correct = $this->find_correct_value( (int) $question->ID );
		if ( null === $correct ) {
			return new ScoringResult( false, 0 );
		}

		$submitted_str = $submitted ? 'true' : 'false';
		$is_correct    = ( $submitted_str === $correct );
		return new ScoringResult( $is_correct, $is_correct ? $points : 0 );
	}

	private function find_correct_value( int $question_id ): ?string {
		$answers = get_post_meta( $question_id, '_vl_question_answers', true );
		if ( ! is_array( $answers ) ) {
			return null;
		}
		foreach ( $answers as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			if ( ! empty( $entry['is_correct'] ) && isset( $entry['text'] ) ) {
				$text = strtolower( trim( (string) $entry['text'] ) );
				if ( 'true' === $text || 'false' === $text ) {
					return $text;
				}
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
