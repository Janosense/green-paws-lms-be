<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Fixtures;

use VL\LMS\Domain\Quiz\QuizAttempt;
use VL\LMS\Domain\Quiz\QuizAttemptStatus;
use VL\LMS\Repositories\QuizAttemptRepository;

/**
 * In-memory double of {@see QuizAttemptRepository} for service-level tests.
 *
 * Same public surface as the real repository, no `$wpdb` calls. Rows live
 * as already-hydrated {@see QuizAttempt} instances keyed by their
 * primary id; `insert()` mints the next id and stores a copy with that id
 * stamped in. The `update_*` paths rebuild the VO immutably so callers
 * always observe a fresh snapshot rather than a mutated reference.
 */
final class InMemoryQuizAttemptRepository extends QuizAttemptRepository {

	/** @var array<int, QuizAttempt> */
	private array $rows = [];

	private int $next_id = 1;

	/** @var callable():\DateTimeImmutable */
	private $clock_fn;

	/**
	 * @param (callable():\DateTimeImmutable)|null $clock UTC clock; defaults to wall-clock UTC.
	 */
	public function __construct( ?callable $clock = null ) {
		parent::__construct( $clock );
		$this->clock_fn = $clock ?? static fn (): \DateTimeImmutable =>
			new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
	}

	public function find( int $id ): ?QuizAttempt {
		return $this->rows[ $id ] ?? null;
	}

	public function find_active_for_user_in_quiz( int $user_id, int $quiz_id ): ?QuizAttempt {
		$candidates = [];
		foreach ( $this->rows as $row ) {
			if ( $row->user_id === $user_id
				&& $row->quiz_id === $quiz_id
				&& QuizAttemptStatus::IN_PROGRESS === $row->status
			) {
				$candidates[] = $row;
			}
		}
		if ( [] === $candidates ) {
			return null;
		}
		usort(
			$candidates,
			static fn ( QuizAttempt $a, QuizAttempt $b ): int => $b->started_at <=> $a->started_at
		);
		return $candidates[0];
	}

	public function count_for_user_in_quiz( int $user_id, int $quiz_id ): int {
		$count = 0;
		foreach ( $this->rows as $row ) {
			if ( $row->user_id === $user_id && $row->quiz_id === $quiz_id ) {
				++$count;
			}
		}
		return $count;
	}

	public function count_submitted_for_user( int $quiz_id, int $user_id ): int {
		$count = 0;
		foreach ( $this->rows as $row ) {
			if ( $row->quiz_id === $quiz_id
				&& $row->user_id === $user_id
				&& QuizAttemptStatus::IN_PROGRESS !== $row->status
			) {
				++$count;
			}
		}
		return $count;
	}

	public function best_score_for_user( int $quiz_id, int $user_id ): ?float {
		$best = null;
		foreach ( $this->rows as $row ) {
			if ( $row->quiz_id !== $quiz_id
				|| $row->user_id !== $user_id
				|| QuizAttemptStatus::SUBMITTED !== $row->status
				|| null === $row->score
				|| $row->max_score <= 0
			) {
				continue;
			}
			$pct = round( ( $row->score / $row->max_score ) * 100, 2 );
			if ( null === $best || $pct > $best ) {
				$best = $pct;
			}
		}
		return $best;
	}

	/**
	 * @return list<QuizAttempt>
	 */
	public function list_for_user_in_quiz( int $user_id, int $quiz_id ): array {
		$out = [];
		foreach ( $this->rows as $row ) {
			if ( $row->user_id === $user_id && $row->quiz_id === $quiz_id ) {
				$out[] = $row;
			}
		}
		usort( $out, static fn ( QuizAttempt $a, QuizAttempt $b ): int => $b->started_at <=> $a->started_at );
		return array_values( $out );
	}

	/**
	 * @param list<int> $user_ids
	 * @return array<int, array{attempts: int, graded: int, passed: int, quizzes: int, quizzes_passed: int}>
	 */
	public function attempt_summary_for_users( array $user_ids ): array {
		if ( [] === $user_ids ) {
			return [];
		}

		$wanted  = array_flip( $user_ids );
		$out     = [];
		$quizzes = [];

		foreach ( $this->rows as $row ) {
			if ( ! isset( $wanted[ $row->user_id ] ) ) {
				continue;
			}
			if ( ! isset( $out[ $row->user_id ] ) ) {
				$out[ $row->user_id ]     = [
					'attempts'       => 0,
					'graded'         => 0,
					'passed'         => 0,
					'quizzes'        => 0,
					'quizzes_passed' => 0,
				];
				$quizzes[ $row->user_id ] = [
					'all'    => [],
					'passed' => [],
				];
			}

			++$out[ $row->user_id ]['attempts'];
			if ( QuizAttemptStatus::IN_PROGRESS !== $row->status ) {
				++$out[ $row->user_id ]['graded'];
			}
			if ( true === $row->passed ) {
				++$out[ $row->user_id ]['passed'];
				$quizzes[ $row->user_id ]['passed'][ $row->quiz_id ] = true;
			}
			$quizzes[ $row->user_id ]['all'][ $row->quiz_id ] = true;
		}

		foreach ( $out as $user_id => $_ ) {
			$out[ $user_id ]['quizzes']        = count( $quizzes[ $user_id ]['all'] );
			$out[ $user_id ]['quizzes_passed'] = count( $quizzes[ $user_id ]['passed'] );
		}

		return $out;
	}

	public function find_best_score_for_user_in_quiz( int $user_id, int $quiz_id ): ?QuizAttempt {
		$candidates = [];
		foreach ( $this->rows as $row ) {
			if ( $row->user_id === $user_id
				&& $row->quiz_id === $quiz_id
				&& QuizAttemptStatus::SUBMITTED === $row->status
			) {
				$candidates[] = $row;
			}
		}
		if ( [] === $candidates ) {
			return null;
		}
		usort(
			$candidates,
			static function ( QuizAttempt $a, QuizAttempt $b ): int {
				$score_a = $a->score ?? -1;
				$score_b = $b->score ?? -1;
				if ( $score_a !== $score_b ) {
					return $score_b <=> $score_a;
				}
				return $b->submitted_at <=> $a->submitted_at;
			}
		);
		return $candidates[0];
	}

	/**
	 * @return list<QuizAttempt>
	 */
	public function list_passed_for_user_in_course( int $user_id, int $course_id ): array {
		$out = [];
		foreach ( $this->rows as $row ) {
			if ( $row->user_id === $user_id
				&& $row->course_id === $course_id
				&& QuizAttemptStatus::SUBMITTED === $row->status
				&& true === $row->passed
			) {
				$out[] = $row;
			}
		}
		usort( $out, static fn ( QuizAttempt $a, QuizAttempt $b ): int => $b->submitted_at <=> $a->submitted_at );
		return array_values( $out );
	}

	public function find_passed_final_exam_for_user_in_course(
		int $user_id,
		int $course_id,
		int $final_exam_quiz_id
	): ?QuizAttempt {
		$candidates = [];
		foreach ( $this->rows as $row ) {
			if ( $row->user_id === $user_id
				&& $row->course_id === $course_id
				&& $row->quiz_id === $final_exam_quiz_id
				&& QuizAttemptStatus::SUBMITTED === $row->status
				&& true === $row->passed
			) {
				$candidates[] = $row;
			}
		}
		if ( [] === $candidates ) {
			return null;
		}
		usort( $candidates, static fn ( QuizAttempt $a, QuizAttempt $b ): int => $b->submitted_at <=> $a->submitted_at );
		return $candidates[0];
	}

	public function insert( QuizAttempt $attempt ): int {
		$id  = $this->next_id++;
		$now = ( $this->clock_fn )();

		$this->rows[ $id ] = new QuizAttempt(
			$id,
			$attempt->user_id,
			$attempt->quiz_id,
			$attempt->course_id,
			$attempt->status,
			$attempt->started_at,
			$attempt->submitted_at,
			$attempt->time_limit_seconds,
			$attempt->time_taken_seconds,
			$attempt->score,
			$attempt->max_score,
			$attempt->passed,
			$attempt->passing_threshold,
			$attempt->question_order,
			$now,
			$now
		);
		return $id;
	}

	public function update_status(
		int $id,
		QuizAttemptStatus $status,
		?\DateTimeImmutable $submitted_at = null
	): bool {
		if ( ! isset( $this->rows[ $id ] ) ) {
			return false;
		}
		$existing = $this->rows[ $id ];
		$now      = ( $this->clock_fn )();

		$this->rows[ $id ] = new QuizAttempt(
			$existing->id,
			$existing->user_id,
			$existing->quiz_id,
			$existing->course_id,
			$status,
			$existing->started_at,
			$submitted_at ?? $existing->submitted_at,
			$existing->time_limit_seconds,
			$existing->time_taken_seconds,
			$existing->score,
			$existing->max_score,
			$existing->passed,
			$existing->passing_threshold,
			$existing->question_order,
			$existing->created_at,
			$now
		);
		return true;
	}

	public function update_final(
		int $id,
		int $score,
		bool $passed,
		int $time_taken_seconds,
		\DateTimeImmutable $submitted_at,
		QuizAttemptStatus $final_status
	): bool {
		if ( ! isset( $this->rows[ $id ] ) ) {
			return false;
		}
		$existing = $this->rows[ $id ];
		$now      = ( $this->clock_fn )();

		$this->rows[ $id ] = new QuizAttempt(
			$existing->id,
			$existing->user_id,
			$existing->quiz_id,
			$existing->course_id,
			$final_status,
			$existing->started_at,
			$submitted_at,
			$existing->time_limit_seconds,
			$time_taken_seconds,
			$score,
			$existing->max_score,
			$passed,
			$existing->passing_threshold,
			$existing->question_order,
			$existing->created_at,
			$now
		);
		return true;
	}
}
