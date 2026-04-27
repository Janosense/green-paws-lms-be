<?php

declare(strict_types=1);

namespace VL\LMS\Catalog\Detail;

/**
 * Time-window registration check, shared by the webinar card and detail
 * transformers.
 *
 * Returns `true` when `now` is inside `[opens_at, closes_at]` (either
 * bound may be empty/missing → that side is unbounded). The capacity
 * gate (max_attendees) is deliberately not enforced yet — the
 * `vl_webinar_registrations` table arrives in Phase 7.
 *
 * Both transformers reuse this helper so the catalog card and the
 * detail page never disagree on whether registration is open.
 *
 * // TODO Phase 7: enforce capacity using the registrations table.
 *
 * @author Tymofii Synianskyi
 */
final class RegistrationWindow {

	public function is_open( int $webinar_id ): bool {
		$opens_at  = (string) get_post_meta( $webinar_id, '_vl_webinar_registration_opens_at', true );
		$closes_at = (string) get_post_meta( $webinar_id, '_vl_webinar_registration_closes_at', true );
		$now       = time();

		if ( '' !== $opens_at ) {
			$opens_ts = strtotime( $opens_at );
			if ( false === $opens_ts || $now < $opens_ts ) {
				return false;
			}
		}

		if ( '' !== $closes_at ) {
			$closes_ts = strtotime( $closes_at );
			if ( false === $closes_ts || $now > $closes_ts ) {
				return false;
			}
		}

		return true;
	}
}
