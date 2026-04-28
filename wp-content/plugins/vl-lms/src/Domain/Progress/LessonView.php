<?php

declare(strict_types=1);

namespace VL\LMS\Domain\Progress;

/**
 * Immutable data carrier for one row of `{prefix}vl_lesson_views`.
 *
 * Each row is a single timestamped event from the lesson player. `payload`
 * is exposed as an already-decoded associative array (or `null`) — the
 * repository performs the JSON round-trip at the storage boundary so
 * consumers never have to think about the wire format.
 *
 * @author Tymofii Synianskyi
 */
final class LessonView {

	/**
	 * @param array<string, mixed>|null $payload
	 */
	public function __construct(
		public readonly int $id,
		public readonly int $user_id,
		public readonly int $lesson_id,
		public readonly ?int $topic_id,
		public readonly string $session_uuid,
		public readonly ViewEventType $event_type,
		public readonly ?int $position_seconds,
		public readonly ?array $payload,
		public readonly \DateTimeImmutable $created_at
	) {
	}
}
