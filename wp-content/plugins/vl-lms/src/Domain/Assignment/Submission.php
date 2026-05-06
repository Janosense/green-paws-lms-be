<?php

declare(strict_types=1);

namespace VL\LMS\Domain\Assignment;

/**
 * Immutable data carrier for one row of `{prefix}vl_assignment_submissions`.
 *
 * Re-submission semantics: the table's `UNIQUE (assignment_id, user_id)`
 * key means at most one row exists per `(assignment, user)`. Re-submitting
 * while still `PENDING` updates the existing row in place; the `with_*()`
 * helpers preserve immutability at the VO layer while the repository
 * persists the rebuilt instance.
 *
 * @author Tymofii Synianskyi
 */
class Submission {

	public function __construct(
		public readonly int $id,
		public readonly int $assignment_id,
		public readonly int $user_id,
		public readonly SubmissionStatus $status,
		public readonly ?string $submission_text,
		public readonly ?string $submission_file_url,
		public readonly ?string $submission_file_name,
		public readonly ?int $score,
		public readonly ?string $feedback,
		public readonly ?int $graded_by,
		public readonly string $submitted_at,
		public readonly ?string $graded_at
	) {
	}

	public function with_grade( int $score, ?string $feedback, int $graded_by, string $graded_at ): self {
		return new self(
			$this->id,
			$this->assignment_id,
			$this->user_id,
			SubmissionStatus::GRADED,
			$this->submission_text,
			$this->submission_file_url,
			$this->submission_file_name,
			$score,
			$feedback,
			$graded_by,
			$this->submitted_at,
			$graded_at
		);
	}

	public function with_rejection( string $feedback, int $graded_by, string $graded_at ): self {
		return new self(
			$this->id,
			$this->assignment_id,
			$this->user_id,
			SubmissionStatus::REJECTED,
			$this->submission_text,
			$this->submission_file_url,
			$this->submission_file_name,
			null,
			$feedback,
			$graded_by,
			$this->submitted_at,
			$graded_at
		);
	}

	public function with_resubmission(
		?string $submission_text,
		?string $submission_file_url,
		?string $submission_file_name,
		string $submitted_at
	): self {
		return new self(
			$this->id,
			$this->assignment_id,
			$this->user_id,
			$this->status,
			$submission_text,
			$submission_file_url,
			$submission_file_name,
			$this->score,
			$this->feedback,
			$this->graded_by,
			$submitted_at,
			$this->graded_at
		);
	}

	/**
	 * Hydrate from the associative array produced by `$wpdb->get_row( ..., ARRAY_A )`.
	 *
	 * @param array<string, mixed> $row
	 */
	public static function from_array( array $row ): self {
		return new self(
			(int) $row['id'],
			(int) $row['assignment_id'],
			(int) $row['user_id'],
			SubmissionStatus::from_string( (string) $row['status'] ),
			self::nullable_string( $row['submission_text'] ?? null ),
			self::nullable_string( $row['submission_file_url'] ?? null ),
			self::nullable_string( $row['submission_file_name'] ?? null ),
			self::nullable_int( $row['score'] ?? null ),
			self::nullable_string( $row['feedback'] ?? null ),
			self::nullable_int( $row['graded_by'] ?? null ),
			(string) $row['submitted_at'],
			self::nullable_string( $row['graded_at'] ?? null )
		);
	}

	private static function nullable_string( mixed $value ): ?string {
		if ( null === $value ) {
			return null;
		}
		return (string) $value;
	}

	private static function nullable_int( mixed $value ): ?int {
		if ( null === $value || '' === $value ) {
			return null;
		}
		return (int) $value;
	}
}
