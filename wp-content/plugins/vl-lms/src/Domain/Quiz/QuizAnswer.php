<?php

declare(strict_types=1);

namespace VL\LMS\Domain\Quiz;

/**
 * Immutable data carrier for one row of `{prefix}vl_quiz_answers`.
 *
 * Created on first save (Phase 6.1 B1 — write-as-you-go) and upserted on
 * each subsequent save to the same `(attempt_id, question_id)`. Scoring
 * columns (`is_correct`, `points_awarded`) are null until submit, when the
 * scoring engine writes them in a single batched update.
 *
 * `answer_data` is exposed as an already-decoded associative array — the
 * repository owns the JSON round-trip at the storage seam. Per-type shape:
 * `{"answer_id": "uuid"}` for `single_choice`, `{"answer_ids": [...]}` for
 * `multiple_choice`, `{"value": bool}` for `true_false`, `{"text": string}`
 * for `text`.
 *
 * @author Tymofii Synianskyi
 */
final class QuizAnswer {

	/**
	 * @param array<string, mixed> $answer_data Decoded answer payload; shape depends on question type.
	 */
	public function __construct(
		public readonly int $id,
		public readonly int $attempt_id,
		public readonly int $question_id,
		public readonly array $answer_data,
		public readonly ?bool $is_correct,
		public readonly ?int $points_awarded,
		public readonly \DateTimeImmutable $answered_at
	) {
	}

	/**
	 * @param array<string, mixed> $row
	 */
	public static function from_array( array $row ): self {
		return new self(
			(int) $row['id'],
			(int) $row['attempt_id'],
			(int) $row['question_id'],
			self::decode_answer_data( $row['answer_data'] ?? null ),
			self::nullable_bool( $row['is_correct'] ?? null ),
			self::nullable_int( $row['points_awarded'] ?? null ),
			new \DateTimeImmutable( (string) $row['answered_at'], new \DateTimeZone( 'UTC' ) )
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		$encoded = json_encode( $this->answer_data );
		return [
			'id'             => $this->id,
			'attempt_id'     => $this->attempt_id,
			'question_id'    => $this->question_id,
			'answer_data'    => false === $encoded ? '{}' : $encoded,
			'is_correct'     => null === $this->is_correct ? null : ( $this->is_correct ? 1 : 0 ),
			'points_awarded' => $this->points_awarded,
			'answered_at'    => $this->answered_at->format( 'Y-m-d H:i:s' ),
		];
	}

	private static function nullable_bool( mixed $value ): ?bool {
		if ( null === $value || '' === $value ) {
			return null;
		}
		return (bool) (int) $value;
	}

	private static function nullable_int( mixed $value ): ?int {
		if ( null === $value || '' === $value ) {
			return null;
		}
		return (int) $value;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function decode_answer_data( mixed $value ): array {
		if ( null === $value || '' === $value ) {
			return [];
		}
		$decoded = json_decode( (string) $value, true );
		if ( ! is_array( $decoded ) ) {
			return [];
		}
		/** @var array<string, mixed> $decoded */
		return $decoded;
	}
}
