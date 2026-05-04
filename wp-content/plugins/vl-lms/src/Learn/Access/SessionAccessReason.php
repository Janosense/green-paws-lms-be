<?php

declare(strict_types=1);

namespace VL\LMS\Learn\Access;

/**
 * Reason discriminator for {@see SessionAccessGate} decisions.
 *
 * Mirrors the shape of `Services/Webinars/WebinarAccessReason` but lives
 * in the Learn namespace because cohort-session access is a learn-layer
 * concern (gated by enrollment in the parent course), not a marketing /
 * registration concern.
 *
 * @author Tymofii Synianskyi
 */
enum SessionAccessReason: string {

	case OK                       = 'ok';
	case COURSE_NOT_FOUND         = 'course_not_found';
	case NOT_ENROLLED             = 'not_enrolled';
	case MEETING_NOT_PROVISIONED  = 'meeting_not_provisioned';
	case JOIN_WINDOW_NOT_OPEN     = 'join_window_not_open';
	case JOIN_WINDOW_CLOSED       = 'join_window_closed';
	case RECORDING_NOT_AVAILABLE  = 'recording_not_available';
	case RECORDING_WINDOW_EXPIRED = 'recording_window_expired';
	case SESSION_CANCELLED        = 'session_cancelled';
}
