<?php

declare(strict_types=1);

namespace VL\LMS\Learn\Progression;

/**
 * The minimum a client needs to point a learner at a blocking quiz.
 *
 * Deliberately not the full quiz node: a lock is attached to *other*
 * entities, so embedding the whole node would duplicate the quiz's own
 * curriculum leaf (status, threshold, best score) once per locked row.
 * Id, slug and title are enough to render "pass X to unlock" and link to
 * it.
 *
 * @author Tymofii Synianskyi
 */
final class QuizRef {

	public function __construct(
		public readonly int $id,
		public readonly string $slug,
		public readonly string $title
	) {
	}

	/**
	 * @return array{id: int, slug: string, title: string}
	 */
	public function to_array(): array {
		return [
			'id'    => $this->id,
			'slug'  => $this->slug,
			'title' => $this->title,
		];
	}
}
