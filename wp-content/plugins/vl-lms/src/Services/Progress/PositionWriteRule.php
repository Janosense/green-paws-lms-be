<?php

declare(strict_types=1);

namespace VL\LMS\Services\Progress;

use VL\LMS\Domain\Progress\Progress;
use VL\LMS\Domain\Progress\ViewEventType;
use WP_Post;

/**
 * Computes the `position_seconds` value to persist on a progress upsert.
 *
 * Three regimes, all driven by the {@see ViewEventType} of the incoming
 * event:
 *
 * - `seek` always overwrites the stored position (explicit user intent;
 *   includes overwriting with `null`).
 * - `progress` / `pause` / `unload` / `play` / `view_start` overwrite only
 *   when the new value is monotonically non-decreasing — this prevents
 *   stale-beacon out-of-order delivery from rewinding the resume position.
 * - `complete` takes the maximum of (current, new). When the parent post
 *   declares a duration via `_vl_lesson_duration_seconds` or
 *   `_vl_topic_duration_seconds`, the result is capped at that duration so
 *   the stored value cannot exceed the entity's declared length.
 *
 * The duration lookup is a `protected` seam ({@see self::read_duration()})
 * so unit tests can subclass and feed deterministic values without booting
 * WordPress.
 *
 * @author Tymofii Synianskyi
 */
class PositionWriteRule {

	/**
	 * Apply the write rule and return the value to persist.
	 *
	 * `$post` is required when `$request->event_type` is `complete` — that's
	 * the only branch that consults `_vl_lesson_duration_seconds` /
	 * `_vl_topic_duration_seconds` for the cap. Other branches ignore it
	 * entirely; callers from non-`complete` paths can pass `null`.
	 */
	public function apply( ?Progress $current, ProgressEventRequest $request, ?WP_Post $post = null ): ?int {
		return match ( $request->event_type ) {
			ViewEventType::SEEK     => $request->position_seconds,
			ViewEventType::COMPLETE => $this->apply_complete( $current, $request, $post ),
			default                 => $this->apply_monotonic( $current, $request ),
		};
	}

	private function apply_monotonic( ?Progress $current, ProgressEventRequest $request ): ?int {
		$new = $request->position_seconds;
		if ( null === $current || null === $current->position_seconds ) {
			return $new;
		}
		if ( null === $new ) {
			return $current->position_seconds;
		}
		return $new >= $current->position_seconds ? $new : $current->position_seconds;
	}

	private function apply_complete( ?Progress $current, ProgressEventRequest $request, ?WP_Post $post ): int {
		$current_pos = $current?->position_seconds ?? 0;
		$new_pos     = $request->position_seconds ?? 0;
		$best        = max( $current_pos, $new_pos );

		if ( ! $post instanceof WP_Post ) {
			return $best;
		}

		$duration = $this->read_duration( $post );
		if ( null === $duration || $duration <= 0 ) {
			return $best;
		}

		return $best > $duration ? $duration : $best;
	}

	/**
	 * Read the declared duration meta for the parent entity.
	 *
	 * Returns `null` when the meta is absent or unparseable; callers treat
	 * that as "no cap". Isolated as `protected` so tests can subclass and
	 * supply a fixture without invoking `get_post_meta`.
	 */
	protected function read_duration( WP_Post $post ): ?int {
		// Phase 7.4: sessions have no position semantics. Reaching this path
		// for a vl_session post means the upstream ProgressService rejection
		// was bypassed — defensive throw to make the contract explicit.
		if ( 'vl_session' === $post->post_type ) {
			throw new \LogicException( 'PositionWriteRule cannot read a duration for vl_session posts.' );
		}

		$key = match ( $post->post_type ) {
			'vl_lesson' => '_vl_lesson_duration_seconds',
			'vl_topic'  => '_vl_topic_duration_seconds',
			default     => null,
		};
		if ( null === $key ) {
			return null;
		}
		$raw = get_post_meta( (int) $post->ID, $key, true );
		if ( ! is_numeric( $raw ) ) {
			return null;
		}
		$value = (int) $raw;
		return $value > 0 ? $value : null;
	}
}
