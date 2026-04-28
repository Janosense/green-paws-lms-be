<?php

declare(strict_types=1);

namespace VL\LMS\Domain\Progress;

/**
 * Player event recorded in `{prefix}vl_lesson_views`.
 *
 * Each case is one discrete signal the lesson player emits. `view_start` and
 * `unload` bracket a session (UUID groups them); `play`, `pause`, and `seek`
 * are user-initiated transport events; `progress` is a periodic heartbeat
 * carrying `position_seconds`; `complete` is fired once the entity passes
 * the completion threshold.
 *
 * @author Tymofii Synianskyi
 */
enum ViewEventType: string {

	case VIEW_START = 'view_start';
	case PLAY       = 'play';
	case PAUSE      = 'pause';
	case SEEK       = 'seek';
	case PROGRESS   = 'progress';
	case COMPLETE   = 'complete';
	case UNLOAD     = 'unload';

	/**
	 * Strict parser. Rejects unknown values with a descriptive exception so
	 * callers never silently mis-type a row.
	 *
	 * @throws \InvalidArgumentException When `$value` is not a recognized case.
	 */
	public static function from_string( string $value ): self {
		$case = self::tryFrom( $value );
		if ( null === $case ) {
			$options = implode( ', ', array_map( static fn ( self $c ): string => $c->value, self::cases() ) );
			throw new \InvalidArgumentException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception, message never surfaces to end users.
				sprintf( 'Unknown view event type "%s". Valid options: %s.', $value, $options )
			);
		}
		return $case;
	}
}
