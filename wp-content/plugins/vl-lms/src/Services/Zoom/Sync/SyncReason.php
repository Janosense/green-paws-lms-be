<?php

declare(strict_types=1);

namespace VL\LMS\Services\Zoom\Sync;

/**
 * Why the synchronizer did not touch Zoom on a given run.
 *
 * Populated only on {@see SyncDecision::NOOP} and
 * {@see SyncDecision::SKIPPED} results — every other decision moved a
 * real Zoom resource and needs no further explanation.
 *
 * @author Tymofii Synianskyi
 */
enum SyncReason: string {

	case NOT_CONFIGURED            = 'not_configured';
	case REVISION_OR_AUTOSAVE      = 'revision_or_autosave';
	case INVALID_POST_STATUS       = 'invalid_post_status';
	case LOCKED                    = 'locked';
	case CANCELLED_WITHOUT_MEETING = 'cancelled_without_meeting';
	case NO_DIFF                   = 'no_diff';
	case MISSING_REQUIRED_META     = 'missing_required_meta';
}
