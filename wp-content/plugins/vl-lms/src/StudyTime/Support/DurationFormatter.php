<?php

declare(strict_types=1);

namespace VL\LMS\StudyTime\Support;

/**
 * Renders a number of seconds as the Ukrainian duration every `study-time`
 * surface shows: «12 год 05 хв», «45 хв» under an hour, «< 1 хв» for a
 * figure that rounds away.
 *
 * Minutes are the smallest unit the customer reads. Seconds would suggest a
 * precision the measurement does not have — one heartbeat is worth up to
 * `cap_seconds`, and the whole feature is analytics rather than certified
 * hours (`docs/DECISIONS.md` 2026-09-15, scope entry). So anything under a
 * minute of real time is reported as «< 1 хв» rather than rounded to zero:
 * a learner who opened a lesson for forty seconds did study, and a «0 хв»
 * would read as "never opened it".
 *
 * Exactly zero is different — it means no signal at all — and renders
 * «0 хв». A surface that prefers an em dash for "nothing recorded"
 * («Панель інструктора» does) decides that for itself; this formatter never
 * returns punctuation a table cell has to interpret.
 *
 * Minutes are zero-padded to two digits only when hours are shown, so a
 * column of «12 год 05 хв» / «1 год 30 хв» stays aligned while a bare
 * «5 хв» does not gain a pointless zero.
 *
 * Static and unregistered, like {@see \VL\LMS\Support\PlainText}: it has no
 * state and no collaborators, and every caller is a renderer.
 *
 * @author Tymofii Synianskyi
 */
final class DurationFormatter {

	private const int SECONDS_PER_MINUTE = 60;

	private const int MINUTES_PER_HOUR = 60;

	public static function uk( int $seconds ): string {
		if ( $seconds <= 0 ) {
			/* translators: %s: a number of minutes, always 0 here. */
			return sprintf( __( '%s хв', 'vl-lms' ), number_format_i18n( 0 ) );
		}

		if ( $seconds < self::SECONDS_PER_MINUTE ) {
			return __( '< 1 хв', 'vl-lms' );
		}

		$total_minutes = intdiv( $seconds, self::SECONDS_PER_MINUTE );
		$hours         = intdiv( $total_minutes, self::MINUTES_PER_HOUR );
		$minutes       = $total_minutes % self::MINUTES_PER_HOUR;

		if ( 0 === $hours ) {
			/* translators: %s: a number of minutes. */
			return sprintf( __( '%s хв', 'vl-lms' ), number_format_i18n( $minutes ) );
		}

		return sprintf(
			/* translators: 1: a number of hours, 2: the remaining minutes, zero-padded to two digits. */
			__( '%1$s год %2$s хв', 'vl-lms' ),
			number_format_i18n( $hours ),
			str_pad( (string) $minutes, 2, '0', STR_PAD_LEFT )
		);
	}
}
