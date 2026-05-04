<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Fixtures;

use VL\LMS\Domain\Quiz\QuizAnswer;
use VL\LMS\Repositories\QuizAnswerRepository;

/**
 * In-memory double of {@see QuizAnswerRepository} for service-level tests.
 *
 * Honors the same `(attempt_id, question_id)` uniqueness as the real
 * `attempt_question` index — `upsert` updates the existing row instead of
 * appending a duplicate. The batched scoring path mirrors the real
 * transaction semantics in spirit (all-or-nothing) by completing the
 * loop without partial visibility — there is no observer that can see a
 * half-scored attempt mid-loop in-memory anyway.
 */
final class InMemoryQuizAnswerRepository extends QuizAnswerRepository {

	/** @var array<int, QuizAnswer> */
	private array $rows = [];

	private int $next_id = 1;

	public function find( int $id ): ?QuizAnswer {
		return $this->rows[ $id ] ?? null;
	}

	public function find_by_attempt_and_question( int $attempt_id, int $question_id ): ?QuizAnswer {
		foreach ( $this->rows as $row ) {
			if ( $row->attempt_id === $attempt_id && $row->question_id === $question_id ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * @return list<QuizAnswer>
	 */
	public function list_for_attempt( int $attempt_id ): array {
		$out = [];
		foreach ( $this->rows as $row ) {
			if ( $row->attempt_id === $attempt_id ) {
				$out[] = $row;
			}
		}
		usort( $out, static fn ( QuizAnswer $a, QuizAnswer $b ): int => $a->id <=> $b->id );
		return array_values( $out );
	}

	public function upsert( QuizAnswer $answer ): int {
		$existing_id = null;
		foreach ( $this->rows as $id => $row ) {
			if ( $row->attempt_id === $answer->attempt_id && $row->question_id === $answer->question_id ) {
				$existing_id = $id;
				break;
			}
		}

		if ( null === $existing_id ) {
			$id                = $this->next_id++;
			$this->rows[ $id ] = new QuizAnswer(
				$id,
				$answer->attempt_id,
				$answer->question_id,
				$answer->answer_data,
				$answer->is_correct,
				$answer->points_awarded,
				$answer->answered_at
			);
			return $id;
		}

		$existing                   = $this->rows[ $existing_id ];
		$this->rows[ $existing_id ] = new QuizAnswer(
			$existing->id,
			$existing->attempt_id,
			$existing->question_id,
			$answer->answer_data,
			$existing->is_correct,
			$existing->points_awarded,
			$answer->answered_at
		);
		return $existing_id;
	}

	public function update_scoring( int $id, bool $is_correct, int $points_awarded ): bool {
		if ( ! isset( $this->rows[ $id ] ) ) {
			return false;
		}
		$existing          = $this->rows[ $id ];
		$this->rows[ $id ] = new QuizAnswer(
			$existing->id,
			$existing->attempt_id,
			$existing->question_id,
			$existing->answer_data,
			$is_correct,
			$points_awarded,
			$existing->answered_at
		);
		return true;
	}

	public function update_scoring_batch( int $attempt_id, array $by_answer_id ): int {
		if ( [] === $by_answer_id ) {
			return 0;
		}
		$affected = 0;
		foreach ( $by_answer_id as $id => $row ) {
			if ( $this->update_scoring( (int) $id, (bool) $row['is_correct'], (int) $row['points_awarded'] ) ) {
				++$affected;
			}
		}
		unset( $attempt_id );
		return $affected;
	}
}
