<?php

declare(strict_types=1);

namespace VL\LMS\Api\Transformers;

use VL\LMS\Domain\Assignment\Submission;

/**
 * Phase 9.4 — REST wire shape for `Submission` rows.
 *
 * Single source of truth for the submission JSON contract; both the
 * student-facing `AssignmentsController` and admin-facing
 * `AdminAssignmentsController` route through this transformer so the wire
 * shape never drifts between the two surfaces.
 *
 * Not declared `final` — tests may subclass to introspect the rendered shape.
 *
 * @author Tymofii Synianskyi
 */
class SubmissionTransformer {

	/**
	 * @return array<string, mixed>
	 */
	public function transform( Submission $submission ): array {
		return [
			'id'                   => $submission->id,
			'assignment_id'        => $submission->assignment_id,
			'status'               => $submission->status->value,
			'submission_text'      => $submission->submission_text,
			'submission_file_url'  => $submission->submission_file_url,
			'submission_file_name' => $submission->submission_file_name,
			'score'                => $submission->score,
			'feedback'             => $submission->feedback,
			'submitted_at'         => $this->to_atom( $submission->submitted_at ),
			'graded_at'            => null === $submission->graded_at ? null : $this->to_atom( $submission->graded_at ),
		];
	}

	private function to_atom( string $datetime ): string {
		try {
			$dt = new \DateTimeImmutable( $datetime, new \DateTimeZone( 'UTC' ) );
		} catch ( \Exception $e ) {
			return $datetime;
		}
		return $dt->format( \DateTimeInterface::ATOM );
	}
}
