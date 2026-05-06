<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Fixtures;

use VL\LMS\Domain\Assignment\Submission;
use VL\LMS\Domain\Assignment\SubmissionStatus;
use VL\LMS\Repositories\AssignmentSubmissionRepository;

/**
 * In-memory double of {@see AssignmentSubmissionRepository} for service-level tests.
 *
 * Same public surface as the real repository, no `$wpdb` calls. Rows live as
 * already-hydrated {@see Submission} instances keyed by their primary id;
 * `insert()` mints the next id and stamps it onto a stored copy. `update()`
 * replaces the stored row with the supplied VO, preserving immutable
 * snapshot semantics.
 */
final class InMemoryAssignmentSubmissionRepository extends AssignmentSubmissionRepository {

	/** @var array<int, Submission> */
	private array $rows = [];

	private int $next_id = 1;

	public function find( int $id ): ?Submission {
		return $this->rows[ $id ] ?? null;
	}

	public function find_by_assignment_user( int $assignment_id, int $user_id ): ?Submission {
		foreach ( $this->rows as $row ) {
			if ( $row->assignment_id === $assignment_id && $row->user_id === $user_id ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * @return list<Submission>
	 */
	public function list_pending( int $page = 1, int $per_page = 20 ): array {
		return $this->paged_filter(
			static fn ( Submission $s ): bool => SubmissionStatus::PENDING === $s->status,
			$page,
			$per_page,
			'asc'
		);
	}

	public function count_pending(): int {
		$count = 0;
		foreach ( $this->rows as $row ) {
			if ( SubmissionStatus::PENDING === $row->status ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * @return list<Submission>
	 */
	public function list_by_status( string $status, int $page = 1, int $per_page = 20 ): array {
		return $this->paged_filter(
			static fn ( Submission $s ): bool => $s->status->value === $status,
			$page,
			$per_page,
			'desc'
		);
	}

	public function count_by_status( string $status ): int {
		$count = 0;
		foreach ( $this->rows as $row ) {
			if ( $row->status->value === $status ) {
				++$count;
			}
		}
		return $count;
	}

	public function insert( Submission $submission ): int {
		$id = $this->next_id++;

		$this->rows[ $id ] = new Submission(
			$id,
			$submission->assignment_id,
			$submission->user_id,
			$submission->status,
			$submission->submission_text,
			$submission->submission_file_url,
			$submission->submission_file_name,
			$submission->score,
			$submission->feedback,
			$submission->graded_by,
			$submission->submitted_at,
			$submission->graded_at
		);
		return $id;
	}

	public function update( Submission $submission ): void {
		if ( ! isset( $this->rows[ $submission->id ] ) ) {
			return;
		}
		$this->rows[ $submission->id ] = $submission;
	}

	/**
	 * @param callable(Submission):bool $predicate
	 * @return list<Submission>
	 */
	private function paged_filter( callable $predicate, int $page, int $per_page, string $sort ): array {
		$matched = [];
		foreach ( $this->rows as $row ) {
			if ( $predicate( $row ) ) {
				$matched[] = $row;
			}
		}
		usort(
			$matched,
			static function ( Submission $a, Submission $b ) use ( $sort ): int {
				return 'asc' === $sort
					? strcmp( $a->submitted_at, $b->submitted_at )
					: strcmp( $b->submitted_at, $a->submitted_at );
			}
		);
		$page     = max( 1, $page );
		$per_page = max( 1, $per_page );
		$offset   = ( $page - 1 ) * $per_page;
		return array_values( array_slice( $matched, $offset, $per_page ) );
	}
}
