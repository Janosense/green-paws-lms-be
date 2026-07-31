<?php

declare(strict_types=1);

namespace VL\LMS\Learn\Progression;

/**
 * One leaf in the canonical curriculum order.
 *
 * A "stop" is anything a learner can navigate to: a lesson page, a topic
 * page, a quiz, or a cohort session. Modules are *not* stops — they are
 * groupings that contribute their children's positions, and the learner
 * never lands on a module URL.
 *
 * Quiz-only fields hang off this same class rather than a subclass
 * because the ordered list is walked positionally: a polymorphic
 * hierarchy would force an instanceof ladder in the hot loop for no
 * gain. Non-quiz stops leave them at their neutral defaults.
 *
 * @author Tymofii Synianskyi
 */
final class CurriculumStop {

	public const string KIND_LESSON  = 'lesson';
	public const string KIND_TOPIC   = 'topic';
	public const string KIND_QUIZ    = 'quiz';
	public const string KIND_SESSION = 'session';

	public function __construct(
		public readonly string $kind,
		public readonly int $id,
		public readonly string $slug = '',
		public readonly string $title = '',
		public readonly bool $blocks_progression = false,
		public readonly bool $requires_all_quizzes = false,
		public readonly bool $is_final_exam = false,
		public readonly bool $has_topics = false
	) {
	}

	public function is_quiz(): bool {
		return self::KIND_QUIZ === $this->kind;
	}

	public function is_session(): bool {
		return self::KIND_SESSION === $this->kind;
	}

	public function to_quiz_ref(): QuizRef {
		return new QuizRef( $this->id, $this->slug, $this->title );
	}

	public function to_entity_ref(): EntityRef {
		return new EntityRef( $this->kind, $this->id, $this->slug, $this->title );
	}

	/**
	 * Stable identity for {@see LockMap} keys. Ids are unique across post
	 * types in WordPress, but keying on kind + id keeps the map readable
	 * in test failures and guards against a future non-post stop.
	 */
	public function key(): string {
		return $this->kind . ':' . $this->id;
	}
}
