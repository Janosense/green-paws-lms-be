<?php

declare(strict_types=1);

namespace VL\LMS\Quiz\Scoring;

use VL\LMS\Domain\Quiz\QuizAnswer;
use WP_Post;

/**
 * Scorer for `multiple_choice` questions.
 *
 * All-or-nothing per the Phase-6 decision (no partial credit). Builds
 * the required set from every entry in `_vl_question_answers` flagged
 * `is_correct=true`, builds the submitted set from
 * `answer.answer_data['answer_ids']`, and compares the two as sets
 * (order-insensitive, duplicate-insensitive). Equal → full points;
 * any mismatch (missing correct OR extra incorrect) → zero.
 *
 * @author Tymofii Synianskyi
 */
class MultipleChoiceScorer {

	public function score( WP_Post $question, ?QuizAnswer $answer ): ScoringResult {
		$points = $this->read_points( (int) $question->ID );

		if ( null === $answer ) {
			return new ScoringResult( false, 0 );
		}

		$submitted_raw = $answer->answer_data['answer_ids'] ?? null;
		if ( ! is_array( $submitted_raw ) ) {
			return new ScoringResult( false, 0 );
		}
		$submitted = $this->normalize_set( $submitted_raw );

		$required = $this->find_correct_answer_ids( (int) $question->ID );
		if ( [] === $required ) {
			return new ScoringResult( false, 0 );
		}

		$is_correct = $required === $submitted;
		return new ScoringResult( $is_correct, $is_correct ? $points : 0 );
	}

	/**
	 * @return list<string>
	 */
	private function find_correct_answer_ids( int $question_id ): array {
		$answers = get_post_meta( $question_id, '_vl_question_answers', true );
		if ( ! is_array( $answers ) ) {
			return [];
		}
		$ids = [];
		foreach ( $answers as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			if ( ! empty( $entry['is_correct'] ) && isset( $entry['id'] ) ) {
				$ids[] = (string) $entry['id'];
			}
		}
		return $this->normalize_set( $ids );
	}

	/**
	 * Coerce, dedupe, and sort so two sets compare cleanly.
	 *
	 * @param array<int|string, mixed> $values
	 *
	 * @return list<string>
	 */
	private function normalize_set( array $values ): array {
		$seen = [];
		foreach ( $values as $value ) {
			if ( ! is_string( $value ) && ! is_int( $value ) ) {
				continue;
			}
			$key = (string) $value;
			if ( '' === $key ) {
				continue;
			}
			$seen[ $key ] = true;
		}
		$out = array_keys( $seen );
		sort( $out );
		return $out;
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
