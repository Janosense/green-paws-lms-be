<?php

declare(strict_types=1);

namespace VL\LMS\Quiz;

use VL\LMS\Domain\Quiz\QuestionType;
use VL\LMS\Domain\Quiz\QuizAttemptStatus;
use VL\LMS\Domain\Quiz\ShowCorrectAnswersPolicy;
use WP_Post;

/**
 * Transforms a list of `vl_quiz_question` posts into the wire shape the
 * client renders.
 *
 * The single non-trivial concern is the reveal-time decision: when does
 * the response include `is_correct` markers, the `correct_text` for text
 * questions, and the `explanation` field? The matrix below combines the
 * quiz's {@see ShowCorrectAnswersPolicy} with the attempt's status and
 * (for `AFTER_PASS`) its `passed` flag:
 *
 * | Policy        | Status      | Passed         | Reveal? |
 * | ------------- | ----------- | -------------- | ------- |
 * | NEVER         | any         | any            | No      |
 * | AFTER_SUBMIT  | IN_PROGRESS | n/a            | No      |
 * | AFTER_SUBMIT  | submitted/expired | any      | Yes     |
 * | AFTER_PASS    | IN_PROGRESS | n/a            | No      |
 * | AFTER_PASS    | submitted/expired | false/null | No   |
 * | AFTER_PASS    | SUBMITTED   | true           | Yes     |
 *
 * The transformer reads `_vl_question_*` meta directly via
 * {@see get_post_meta()} — it accepts a pre-fetched `WP_Post[]` so callers
 * (the service) own the question-list query and the transformer stays
 * loop-free against `WP_Query`.
 *
 * @author Tymofii Synianskyi
 */
class QuestionDeliveryTransformer {

	/**
	 * @param list<WP_Post> $questions
	 *
	 * @return list<array<string, mixed>>
	 */
	public function deliver_for_attempt_view(
		array $questions,
		ShowCorrectAnswersPolicy $policy,
		QuizAttemptStatus $attempt_status,
		?bool $passed
	): array {
		$reveal = $this->should_reveal( $policy, $attempt_status, $passed );

		$out = [];
		foreach ( $questions as $question ) {
			$out[] = $this->transform_one( $question, $reveal );
		}
		return $out;
	}

	private function should_reveal(
		ShowCorrectAnswersPolicy $policy,
		QuizAttemptStatus $attempt_status,
		?bool $passed
	): bool {
		if ( ShowCorrectAnswersPolicy::NEVER === $policy ) {
			return false;
		}
		if ( QuizAttemptStatus::IN_PROGRESS === $attempt_status ) {
			return false;
		}
		if ( ShowCorrectAnswersPolicy::AFTER_SUBMIT === $policy ) {
			return true;
		}
		// AFTER_PASS — reveal only on a passed SUBMITTED attempt.
		// EXPIRED attempts (server-detected overrun) keep markers hidden
		// because the policy only grants the reveal on actual pass.
		return QuizAttemptStatus::SUBMITTED === $attempt_status && true === $passed;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function transform_one( WP_Post $question, bool $reveal ): array {
		$id    = (int) $question->ID;
		$type  = QuestionType::from_meta_value( $this->meta_string( $id, '_vl_question_type' ) );
		$out   = [
			'id'        => $id,
			'type'      => $type->value,
			'title'     => (string) $question->post_title,
			'media_url' => $this->meta_string( $id, '_vl_question_media_url' ),
			'points'    => $this->meta_int( $id, '_vl_question_points' ),
		];

		if ( QuestionType::TEXT === $type ) {
			if ( $reveal ) {
				$out['correct_text'] = $this->meta_string( $id, '_vl_question_correct_text' );
				$out['match_mode']   = $this->meta_string( $id, '_vl_question_match_mode' );
			}
		} else {
			$out['answers'] = $this->transform_answers( $id, $reveal );
		}

		if ( $reveal ) {
			$explanation = $this->meta_string( $id, '_vl_question_explanation' );
			if ( '' !== $explanation ) {
				$out['explanation'] = $explanation;
			}
		}

		return $out;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function transform_answers( int $question_id, bool $reveal ): array {
		$raw = get_post_meta( $question_id, '_vl_question_answers', true );
		if ( ! is_array( $raw ) ) {
			return [];
		}

		$out = [];
		foreach ( $raw as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$row = [
				'id'   => isset( $entry['id'] ) ? (string) $entry['id'] : '',
				'text' => isset( $entry['text'] ) ? (string) $entry['text'] : '',
			];
			if ( $reveal ) {
				$row['is_correct'] = ! empty( $entry['is_correct'] );
			}
			$out[] = $row;
		}
		return $out;
	}

	private function meta_string( int $post_id, string $key ): string {
		$value = get_post_meta( $post_id, $key, true );
		if ( is_string( $value ) ) {
			return $value;
		}
		return '';
	}

	private function meta_int( int $post_id, string $key ): int {
		$value = get_post_meta( $post_id, $key, true );
		if ( is_string( $value ) || is_int( $value ) ) {
			return (int) $value;
		}
		return 0;
	}
}
