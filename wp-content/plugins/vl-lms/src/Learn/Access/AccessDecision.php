<?php

declare(strict_types=1);

namespace VL\LMS\Learn\Access;

/**
 * Immutable verdict produced by {@see LessonAccessGate::check()}.
 *
 * `$reason` carries one of a fixed set of strings on a deny verdict, or
 * `null` on allow. The frontend (5.4) maps these into i18n strings; the
 * REST layer (5.1b) maps them into HTTP error codes.
 *
 * @author Tymofii Synianskyi
 */
final class AccessDecision {

	public function __construct(
		public readonly bool $allowed,
		public readonly ?string $reason,
		public readonly int $course_id,
		public readonly bool $is_preview
	) {
	}

	public static function allow( int $course_id, bool $is_preview ): self {
		return new self( true, null, $course_id, $is_preview );
	}

	public static function deny( string $reason, int $course_id = 0 ): self {
		return new self( false, $reason, $course_id, false );
	}
}
