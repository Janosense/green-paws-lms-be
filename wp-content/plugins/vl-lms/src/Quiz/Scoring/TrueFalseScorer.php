<?php

declare(strict_types=1);

namespace VL\LMS\Quiz\Scoring;

use VL\LMS\Domain\Quiz\QuizAnswer;
use WP_Post;

/**
 * Scorer for `true_false` questions.
 *
 * The question stores its answers in `_vl_question_answers` in the same
 * shape as choice questions; the entry flagged `is_correct=true` names the
 * expected side. The submitted answer arrives as a real PHP `bool` in
 * `answer.answer_data['value']`, so that flagged entry has to resolve back
 * to a bool. Two rules, in this order — the same order (and the same
 * fallback) the player uses in `QuizInputTrueFalse.vue`:
 *
 * 1. **Text**, when it lower-cases to `"true"` / `"false"` — the canonical
 *    authoring shape, and the only one that survives a reordered list.
 * 2. **Position** otherwise: first delivered entry is the *true* side,
 *    second is the *false* side.
 *
 * Rule 2 is load-bearing, not a courtesy. The answers builder in wp-admin
 * is a free-text list with no true/false affordance, so a human authoring
 * "Правда / Неправда" writes whatever labels they like (the bundled demo
 * seeder writes `Так` / `Ні`) — and the player never renders those labels
 * anyway, it renders its own i18n strings against slot 0 and slot 1. So
 * what the learner actually picks is a *slot*, and the slot the author
 * ticked is the only thing that can define correctness. Matching text
 * alone made every non-English question unanswerable: no entry matched,
 * the expected value came back `null`, and the scorer returned zero for a
 * correct answer. Note this rules out a synonym table (`так`, `yes`, `1`):
 * an author who lists `Неправда` first still has slot 0 labelled "Правда"
 * on screen, so translating the text would score the opposite of what the
 * results screen marks correct.
 *
 * Match → full points; mismatch or unresolvable → zero.
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

		// Positional index counts only well-formed entries, because that is
		// what the delivered `answers[]` array is indexed by — malformed rows
		// are dropped by QuestionDeliveryTransformer before the player sees
		// them, so counting them here would shift every slot by one.
		$index      = 0;
		$positional = null;

		foreach ( $answers as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			if ( empty( $entry['is_correct'] ) ) {
				++$index;
				continue;
			}

			$text = isset( $entry['text'] ) ? strtolower( trim( (string) $entry['text'] ) ) : '';
			if ( 'true' === $text || 'false' === $text ) {
				return $text;
			}

			// Hold the positional reading rather than returning it — a later
			// entry carrying canonical text still outranks it. Slots beyond
			// the second have no true/false meaning at all.
			if ( null === $positional && $index <= 1 ) {
				$positional = 0 === $index ? 'true' : 'false';
			}
			++$index;
		}

		return $positional;
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
