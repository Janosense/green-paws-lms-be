<?php

declare(strict_types=1);

namespace VL\LMS\Learn\Access;

use VL\LMS\Learn\Progression\LockState;

/**
 * Immutable verdict produced by {@see LessonAccessGate::check()}.
 *
 * `$reason` carries one of a fixed set of strings on a deny verdict, or
 * `null` on allow. The frontend (5.4) maps these into i18n strings; the
 * REST layer (5.1b) maps them into HTTP error codes.
 *
 * `$lock` is populated only on the two progression-gating denials, where
 * the reason string alone is not actionable: the client needs to know
 * *which* quiz to go pass. Every other denial leaves it `null`, and so
 * does every allow.
 *
 * @author Tymofii Synianskyi
 */
final class AccessDecision {

	public function __construct(
		public readonly bool $allowed,
		public readonly ?string $reason,
		public readonly int $course_id,
		public readonly bool $is_preview,
		public readonly ?LockState $lock = null
	) {
	}

	public static function allow( int $course_id, bool $is_preview ): self {
		return new self( true, null, $course_id, $is_preview );
	}

	public static function deny( string $reason, int $course_id = 0, ?LockState $lock = null ): self {
		return new self( false, $reason, $course_id, false, $lock );
	}

	/**
	 * Denial built straight from a lock verdict, so the reason string and
	 * the payload can never disagree.
	 */
	public static function deny_locked( LockState $lock, int $course_id ): self {
		return self::deny( $lock->reason, $course_id, $lock );
	}
}
