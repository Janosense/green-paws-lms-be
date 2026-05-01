<?php

declare(strict_types=1);

namespace VL\LMS\Services\Zoom\Sync;

/**
 * Outcome category for a single {@see MeetingSynchronizer::sync()} run.
 *
 * The orchestrator's decision table maps the post's current shape (status,
 * existing meeting_id, payload completeness) to one of these values; the
 * matching {@see SyncResult} carries it back to the caller.
 *
 * @author Tymofii Synianskyi
 */
enum SyncDecision: string {

	case CREATE  = 'create';
	case UPDATE  = 'update';
	case DELETE  = 'delete';
	case NOOP    = 'noop';
	case SKIPPED = 'skipped';
}
