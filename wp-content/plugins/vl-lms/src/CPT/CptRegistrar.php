<?php

declare(strict_types=1);

namespace VL\LMS\CPT;

/**
 * Aggregates per-CPT registrars and wires them to WordPress.
 *
 * Owns the canonical, ordered list of every VL LMS post type. Each entry
 * is an `AbstractCptRegistrar` instance that knows how to register itself
 * (post type + meta). On `init` we walk the list in order so the parent
 * (`vl_course`) is registered before the children that reference it via
 * `post_parent`.
 *
 * Direct instantiation is fine here: the list is short, fixed at
 * declaration time, and has no external configuration.
 *
 * @author Tymofii Synianskyi
 */
final class CptRegistrar {

	/**
	 * @var list<AbstractCptRegistrar>
	 */
	private array $registrars;

	public function __construct() {
		$this->registrars = [
			new CourseType(),
			new ModuleType(),
			new LessonType(),
			new TopicType(),
			new SessionType(),
			new WebinarType(),
			new QuizType(),
			new QuizQuestionType(),
			new AssignmentType(),
		];
	}

	public function register_hooks(): void {
		add_action( 'init', [ $this, 'register_all' ], 10 );
	}

	public function register_all(): void {
		foreach ( $this->registrars as $registrar ) {
			$registrar->register();
		}
	}

	/**
	 * @return list<AbstractCptRegistrar>
	 */
	public function registrars(): array {
		return $this->registrars;
	}
}
