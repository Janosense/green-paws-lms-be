<?php

declare(strict_types=1);

namespace VL\LMS\Domain\Quiz;

/**
 * Immutable data carrier for one row of `{prefix}vl_quiz_attempts`.
 *
 * Snapshot semantics: `time_limit_seconds`, `passing_threshold`, `max_score`,
 * and `question_order` are frozen on `start()` (Phase 6.1) and never re-read
 * from the source quiz CPT — historical attempts stay grounded in the quiz
 * config that was live when the learner began. Date columns are surfaced as
 * `DateTimeImmutable` (UTC); the `question_order` JSON column is exposed as
 * a `list<int>` (the repository is the only place that touches the JSON
 * round-trip).
 *
 * @author Tymofii Synianskyi
 */
final class QuizAttempt {

	/**
	 * @param list<int> $question_order Ordered list of question post IDs frozen at start time.
	 */
	public function __construct(
		public readonly int $id,
		public readonly int $user_id,
		public readonly int $quiz_id,
		public readonly int $course_id,
		public readonly QuizAttemptStatus $status,
		public readonly \DateTimeImmutable $started_at,
		public readonly ?\DateTimeImmutable $submitted_at,
		public readonly int $time_limit_seconds,
		public readonly ?int $time_taken_seconds,
		public readonly ?int $score,
		public readonly int $max_score,
		public readonly ?bool $passed,
		public readonly int $passing_threshold,
		public readonly array $question_order,
		public readonly \DateTimeImmutable $created_at,
		public readonly \DateTimeImmutable $updated_at
	) {
	}

	/**
	 * Hydrate from the associative array produced by
	 * `$wpdb->get_row( ..., ARRAY_A )`.
	 *
	 * @param array<string, mixed> $row
	 *
	 * @throws \InvalidArgumentException When `status` carries an unrecognized value.
	 */
	public static function from_array( array $row ): self {
		return new self(
			(int) $row['id'],
			(int) $row['user_id'],
			(int) $row['quiz_id'],
			(int) $row['course_id'],
			QuizAttemptStatus::from_string( (string) $row['status'] ),
			self::datetime( (string) $row['started_at'] ),
			self::nullable_datetime( $row['submitted_at'] ?? null ),
			(int) $row['time_limit_seconds'],
			self::nullable_int( $row['time_taken_seconds'] ?? null ),
			self::nullable_int( $row['score'] ?? null ),
			(int) $row['max_score'],
			self::nullable_bool( $row['passed'] ?? null ),
			(int) $row['passing_threshold'],
			self::decode_question_order( $row['question_order'] ?? null ),
			self::datetime( (string) $row['created_at'] ),
			self::datetime( (string) $row['updated_at'] )
		);
	}

	/**
	 * Serialize to the associative-array shape the repository writes back.
	 *
	 * Inverse of {@see self::from_array()} — datetimes become MySQL strings,
	 * `question_order` becomes a JSON-encoded array, and nullable columns
	 * preserve `null`.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		$encoded = json_encode( $this->question_order );
		return [
			'id'                 => $this->id,
			'user_id'            => $this->user_id,
			'quiz_id'            => $this->quiz_id,
			'course_id'          => $this->course_id,
			'status'             => $this->status->value,
			'started_at'         => $this->started_at->format( 'Y-m-d H:i:s' ),
			'submitted_at'       => null === $this->submitted_at ? null : $this->submitted_at->format( 'Y-m-d H:i:s' ),
			'time_limit_seconds' => $this->time_limit_seconds,
			'time_taken_seconds' => $this->time_taken_seconds,
			'score'              => $this->score,
			'max_score'          => $this->max_score,
			'passed'             => null === $this->passed ? null : ( $this->passed ? 1 : 0 ),
			'passing_threshold'  => $this->passing_threshold,
			'question_order'     => false === $encoded ? '[]' : $encoded,
			'created_at'         => $this->created_at->format( 'Y-m-d H:i:s' ),
			'updated_at'         => $this->updated_at->format( 'Y-m-d H:i:s' ),
		];
	}

	public function is_completed(): bool {
		return QuizAttemptStatus::SUBMITTED === $this->status
			|| QuizAttemptStatus::EXPIRED === $this->status;
	}

	public function is_in_progress(): bool {
		return QuizAttemptStatus::IN_PROGRESS === $this->status;
	}

	/**
	 * Score as an integer percentage, rounded half-up, or `null` if the
	 * attempt has not been scored yet. A `max_score` of 0 returns 0
	 * rather than dividing by zero — this matches the empty-quiz edge
	 * case that fails the integrity check at start time anyway, but the
	 * repository round-trip path needs a sensible value.
	 */
	public function score_pct(): ?int {
		if ( null === $this->score ) {
			return null;
		}
		if ( 0 === $this->max_score ) {
			return 0;
		}
		return (int) round( ( $this->score / $this->max_score ) * 100, 0, PHP_ROUND_HALF_UP );
	}

	private static function datetime( string $value ): \DateTimeImmutable {
		return new \DateTimeImmutable( $value, new \DateTimeZone( 'UTC' ) );
	}

	private static function nullable_datetime( mixed $value ): ?\DateTimeImmutable {
		if ( null === $value || '' === $value ) {
			return null;
		}
		return self::datetime( (string) $value );
	}

	private static function nullable_int( mixed $value ): ?int {
		if ( null === $value || '' === $value ) {
			return null;
		}
		return (int) $value;
	}

	private static function nullable_bool( mixed $value ): ?bool {
		if ( null === $value || '' === $value ) {
			return null;
		}
		return (bool) (int) $value;
	}

	/**
	 * @return list<int>
	 */
	private static function decode_question_order( mixed $value ): array {
		if ( null === $value || '' === $value ) {
			return [];
		}
		$decoded = json_decode( (string) $value, true );
		if ( ! is_array( $decoded ) ) {
			return [];
		}
		$out = [];
		foreach ( $decoded as $item ) {
			$out[] = (int) $item;
		}
		return $out;
	}
}
