<?php

declare(strict_types=1);

namespace VL\LMS\Learn;

use VL\LMS\Domain\Progress\EntityType;
use VL\LMS\Domain\Progress\Progress;

/**
 * Keyed lookup of {@see Progress} rows for one user/course pair.
 *
 * The curriculum endpoint needs O(1) access to progress per leaf entity
 * during a tree walk. Materialising the lookup once at the top of
 * {@see CurriculumTransformer::transform()} means a single
 * `ProgressRepository::list_for_user_in_course()` round trip — no fan-out.
 *
 * Module-level rows are intentionally dropped on construction: the
 * curriculum response does not surface module progress (the frontend
 * computes module state visually from constituent lessons), so they would
 * be dead weight in the lookup.
 *
 * @author Tymofii Synianskyi
 */
final class ProgressOverlay {

	/**
	 * @param array<int, Progress> $by_lesson Keyed by lesson post ID.
	 * @param array<int, Progress> $by_topic  Keyed by topic post ID.
	 */
	public function __construct(
		private readonly array $by_lesson,
		private readonly array $by_topic
	) {
	}

	/**
	 * Partition a flat `Progress[]` list (typically the result of
	 * {@see \VL\LMS\Repositories\ProgressRepository::list_for_user_in_course()})
	 * by `entity_type`. `entity_type='module'` rows are silently discarded.
	 *
	 * @param list<Progress> $progress_rows
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Mirrors PHP's BackedEnum::tryFrom() naming convention.
	public static function fromList( array $progress_rows ): self {
		$by_lesson = [];
		$by_topic  = [];
		foreach ( $progress_rows as $row ) {
			if ( EntityType::LESSON === $row->entity_type ) {
				$by_lesson[ $row->entity_id ] = $row;
				continue;
			}
			if ( EntityType::TOPIC === $row->entity_type ) {
				$by_topic[ $row->entity_id ] = $row;
				continue;
			}
		}
		return new self( $by_lesson, $by_topic );
	}

	public function lesson( int $lesson_id ): ?Progress {
		return $this->by_lesson[ $lesson_id ] ?? null;
	}

	public function topic( int $topic_id ): ?Progress {
		return $this->by_topic[ $topic_id ] ?? null;
	}
}
