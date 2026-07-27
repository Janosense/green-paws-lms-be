<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Fixtures;

use VL\LMS\Learn\Progression\LockMap;
use VL\LMS\Learn\Progression\LockState;
use VL\LMS\Learn\Progression\ProgressionGate;

/**
 * Progression gate that returns a canned verdict and records its calls.
 *
 * Subclasses {@see ProgressionGate} without invoking its constructor —
 * every method that would touch the real collaborators is overridden, so
 * the parent's readonly properties are never read. That lets the access
 * gates be tested against a real type without standing up a
 * `CurriculumOrder` and a `QuizAttemptRepository`.
 *
 * `$calls` exists so a test can assert the gate was *not* consulted —
 * which is the whole point of the preview bypass, and is not observable
 * from the returned verdict alone.
 *
 * @author Tymofii Synianskyi
 */
final class StubProgressionGate extends ProgressionGate {

	/** @var list<array{user_id: int, course_id: int, kind: string, entity_id: int}> */
	public array $calls = [];

	// phpcs:ignore Squiz.Commenting.FunctionComment.Missing -- Deliberately bypasses the parent constructor; see class docblock.
	public function __construct(
		private readonly ?LockState $verdict = null,
		private readonly ?LockMap $map = null
	) {
	}

	public function check( int $user_id, int $course_id, string $kind, int $entity_id ): ?LockState {
		$this->calls[] = [
			'user_id'   => $user_id,
			'course_id' => $course_id,
			'kind'      => $kind,
			'entity_id' => $entity_id,
		];
		return $this->verdict;
	}

	public function lock_map( int $user_id, int $course_id ): LockMap {
		return $this->map ?? LockMap::empty();
	}

	public function was_called(): bool {
		return [] !== $this->calls;
	}
}
