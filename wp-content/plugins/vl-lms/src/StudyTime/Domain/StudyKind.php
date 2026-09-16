<?php

declare(strict_types=1);

namespace VL\LMS\StudyTime\Domain;

/**
 * The kind of active study time a ledger row accrues.
 *
 * Stored as the `kind` column of `{prefix}vl_study_time` and sent as `kind`
 * in the heartbeat body. `video` accrues while the player plays in a visible
 * tab, `reading` while it does not and the learner interacts inside the idle
 * window (`docs/DECISIONS.md` 2026-09-15 — kind rules). `quiz` and `session`
 * time are read from their own tables and never have a case here.
 *
 * @author Tymofii Synianskyi
 */
enum StudyKind: string {

	case VIDEO   = 'video';
	case READING = 'reading';
}
