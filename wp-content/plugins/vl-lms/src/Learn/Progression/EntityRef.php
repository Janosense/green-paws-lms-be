<?php

declare(strict_types=1);

namespace VL\LMS\Learn\Progression;

/**
 * The minimum a client needs to point a learner at a blocking lesson or
 * topic — the sequential-mode counterpart of {@see QuizRef}.
 *
 * A separate class rather than a `kind` field on QuizRef because the
 * `blocking_quiz` wire shape is pinned without one, and a quiz reference
 * never needs a kind — its key is unambiguous. Here the client must know
 * whether to link `/learn/{slug}` or `/learn/{lesson}/{slug}`, so the
 * kind travels with the ref.
 *
 * @author Tymofii Synianskyi
 */
final class EntityRef {

	public function __construct(
		public readonly string $kind,
		public readonly int $id,
		public readonly string $slug,
		public readonly string $title
	) {
	}

	/**
	 * @return array{kind: string, id: int, slug: string, title: string}
	 */
	public function to_array(): array {
		return [
			'kind'  => $this->kind,
			'id'    => $this->id,
			'slug'  => $this->slug,
			'title' => $this->title,
		];
	}
}
