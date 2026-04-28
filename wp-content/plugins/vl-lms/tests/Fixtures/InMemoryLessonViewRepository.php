<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Fixtures;

use VL\LMS\Domain\Progress\LessonView;
use VL\LMS\Domain\Progress\ViewEventType;
use VL\LMS\Repositories\LessonViewRepository;

/**
 * In-memory double of {@see LessonViewRepository} for service-level tests.
 *
 * Same public surface as the real repository, no `$wpdb` calls. Append-only
 * — no update path, mirroring the real repository.
 */
final class InMemoryLessonViewRepository extends LessonViewRepository {

	/** @var array<int, LessonView> */
	private array $rows = [];

	private int $next_id = 1;

	public function insert(
		int $user_id,
		int $lesson_id,
		?int $topic_id,
		string $session_uuid,
		ViewEventType $event_type,
		?int $position_seconds,
		?array $payload,
		\DateTimeImmutable $created_at
	): LessonView {
		$id = $this->next_id++;

		$row = new LessonView(
			$id,
			$user_id,
			$lesson_id,
			$topic_id,
			$session_uuid,
			$event_type,
			$position_seconds,
			$payload,
			$created_at
		);

		$this->rows[ $id ] = $row;

		return $row;
	}

	public function find_by_id( int $id ): ?LessonView {
		return $this->rows[ $id ] ?? null;
	}

	/**
	 * @return list<LessonView>
	 */
	public function list_for_session( string $session_uuid ): array {
		$out = [];
		foreach ( $this->rows as $row ) {
			if ( $row->session_uuid === $session_uuid ) {
				$out[] = $row;
			}
		}
		return $out;
	}

	public function delete_for_user( int $user_id ): int {
		$deleted = 0;
		foreach ( $this->rows as $id => $row ) {
			if ( $row->user_id === $user_id ) {
				unset( $this->rows[ $id ] );
				++$deleted;
			}
		}
		return $deleted;
	}
}
