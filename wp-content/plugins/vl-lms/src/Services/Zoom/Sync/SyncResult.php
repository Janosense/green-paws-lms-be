<?php

declare(strict_types=1);

namespace VL\LMS\Services\Zoom\Sync;

/**
 * Immutable result of a single {@see MeetingSynchronizer::sync()} run.
 *
 * Every public path through the orchestrator returns one of these — even
 * the failure branches, which capture the wrapped {@see \Throwable} and
 * the decision the synchronizer was about to take when it threw. Callers
 * therefore never need to wrap `sync()` in their own try / catch.
 *
 * Construct via the named constructors ({@see created()}, {@see updated()},
 * {@see deleted()}, {@see noop()}, {@see skipped()}, {@see failed()})
 * rather than the public constructor — those enforce the field invariants
 * the tests rely on (e.g. `meeting_id` non-null on CREATE/UPDATE only).
 *
 * @author Tymofii Synianskyi
 */
final class SyncResult {

	public function __construct(
		public readonly int $post_id,
		public readonly PostKind $kind,
		public readonly SyncDecision $decision,
		public readonly ?SyncReason $reason = null,
		public readonly ?string $meeting_id = null,
		public readonly ?\Throwable $exception = null
	) {
	}

	public static function created( int $post_id, PostKind $kind, string $meeting_id ): self {
		return new self( $post_id, $kind, SyncDecision::CREATE, null, $meeting_id, null );
	}

	public static function updated( int $post_id, PostKind $kind, string $meeting_id ): self {
		return new self( $post_id, $kind, SyncDecision::UPDATE, null, $meeting_id, null );
	}

	public static function deleted( int $post_id, PostKind $kind ): self {
		return new self( $post_id, $kind, SyncDecision::DELETE, null, null, null );
	}

	public static function noop( int $post_id, PostKind $kind, SyncReason $reason ): self {
		return new self( $post_id, $kind, SyncDecision::NOOP, $reason, null, null );
	}

	public static function skipped( int $post_id, PostKind $kind, SyncReason $reason ): self {
		return new self( $post_id, $kind, SyncDecision::SKIPPED, $reason, null, null );
	}

	public static function failed( int $post_id, PostKind $kind, SyncDecision $intended, \Throwable $exception ): self {
		return new self( $post_id, $kind, $intended, null, null, $exception );
	}
}
