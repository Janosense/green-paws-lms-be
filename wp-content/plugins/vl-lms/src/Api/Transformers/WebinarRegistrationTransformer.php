<?php

declare(strict_types=1);

namespace VL\LMS\Api\Transformers;

use VL\LMS\Catalog\Transformers\CoverImageTransformer;
use VL\LMS\Domain\WebinarRegistration\WebinarRegistration;
use VL\LMS\Services\JoinWindowPolicy;
use VL\LMS\Services\Webinars\WebinarAccessGate;
use VL\LMS\Services\Webinars\WebinarAccessReason;
use VL\LMS\Support\PlainText;
use WP_Post;

/**
 * Projects a {@see WebinarRegistration} plus its `vl_webinar` post into
 * the wire shape returned by `GET /vl/v1/webinars/me`.
 *
 * The `computed.*` block is the controller's primary value-add — the
 * frontend uses `join_window_open` / `recording_available` to pick the
 * right CTA without re-implementing the windowing math, and
 * `join_opens_at` / `join_closes_at` are surfaced for countdown displays.
 *
 * Impure by design: it consults {@see WebinarAccessGate} per row. That's
 * one extra `find_active` query per item — acceptable for a personal
 * dashboard with a small N.
 *
 * @author Tymofii Synianskyi
 */
// Not `final`: controller-level unit tests mock this transformer via Mockery,
// which cannot replace methods on final classes. There are no production
// subclasses today and none planned.
class WebinarRegistrationTransformer {

	public function __construct(
		private readonly WebinarAccessGate $gate,
		private readonly CoverImageTransformer $cover,
		private readonly JoinWindowPolicy $window_policy,
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function transform(
		WebinarRegistration $registration,
		WP_Post $webinar,
		\DateTimeImmutable $now
	): array {
		$webinar_id = (int) $webinar->ID;

		$join_decision      = $this->gate->can_join( $webinar, $registration->user_id );
		$recording_decision = $this->gate->can_view_recording( $webinar, $registration->user_id );

		$start_raw = (string) get_post_meta( $webinar_id, '_vl_webinar_scheduled_start', true );
		$end_raw   = (string) get_post_meta( $webinar_id, '_vl_webinar_scheduled_end', true );
		$end_dt    = $this->parse_iso8601( $end_raw );

		$opens_at_iso  = $this->shift_iso( $start_raw, -( $this->window_policy->early_grace_minutes * 60 ) );
		$closes_at_iso = $this->shift_iso( $end_raw, $this->window_policy->late_grace_minutes * 60 );

		$cover_id = (int) get_post_meta( $webinar_id, '_vl_webinar_cover_image_id', true );

		$price    = (float) get_post_meta( $webinar_id, '_vl_webinar_price', true );
		$currency = (string) get_post_meta( $webinar_id, '_vl_webinar_currency', true );
		if ( '' === $currency ) {
			$currency = 'UAH';
		}

		$capacity              = (int) get_post_meta( $webinar_id, '_vl_webinar_max_attendees', true );
		$status                = (string) get_post_meta( $webinar_id, '_vl_webinar_status', true );
		$recording_access_days = (int) get_post_meta( $webinar_id, '_vl_webinar_recording_access_days', true );
		$recording_until       = (string) get_post_meta( $webinar_id, '_vl_webinar_recording_available_until', true );
		$registration_opens    = (string) get_post_meta( $webinar_id, '_vl_webinar_registration_opens_at', true );
		$registration_closes   = (string) get_post_meta( $webinar_id, '_vl_webinar_registration_closes_at', true );

		return [
			'id'                        => $registration->id,
			'status'                    => $registration->status->value,
			'source'                    => $registration->source->value,
			'registered_at'             => $this->mysql_to_iso( $registration->registered_at ),
			'cancelled_at'              => null === $registration->cancelled_at
				? null
				: $this->mysql_to_iso( $registration->cancelled_at ),
			'attended'                  => $registration->attended,
			'attended_duration_seconds' => $registration->attended_duration_seconds,
			'webinar'                   => [
				'id'                        => $webinar_id,
				'slug'                      => (string) $webinar->post_name,
				'title'                     => $this->title( $webinar ),
				'scheduled_start'           => '' === $start_raw ? null : $start_raw,
				'scheduled_end'             => '' === $end_raw ? null : $end_raw,
				'registration_opens_at'     => '' === $registration_opens ? null : $registration_opens,
				'registration_closes_at'    => '' === $registration_closes ? null : $registration_closes,
				'price'                     => [
					'amount'   => $price,
					'currency' => $currency,
				],
				'capacity'                  => $capacity,
				'cover'                     => $this->cover->transform( $cover_id ),
				'status'                    => '' === $status ? 'scheduled' : $status,
				'recording_access_days'     => $recording_access_days,
				'recording_available_until' => '' === $recording_until ? null : $recording_until,
			],
			'computed'                  => [
				'join_window_open'    => $join_decision->allowed
					|| WebinarAccessReason::OK === $join_decision->reason,
				'join_opens_at'       => $opens_at_iso,
				'join_closes_at'      => $closes_at_iso,
				'recording_available' => $recording_decision->allowed,
				'is_past'             => null !== $end_dt && $now > $end_dt,
			],
		];
	}

	private function title( WP_Post $webinar ): string {
		return PlainText::from_html( (string) get_the_title( $webinar ) );
	}

	private function parse_iso8601( string $value ): ?\DateTimeImmutable {
		if ( '' === $value ) {
			return null;
		}
		try {
			return new \DateTimeImmutable( $value );
		} catch ( \Throwable ) {
			return null;
		}
	}

	private function shift_iso( string $iso_value, int $delta_seconds ): ?string {
		$dt = $this->parse_iso8601( $iso_value );
		if ( null === $dt ) {
			return null;
		}
		$shifted = 0 === $delta_seconds
			? $dt
			: $dt->modify( ( $delta_seconds < 0 ? '-' : '+' ) . abs( $delta_seconds ) . ' seconds' );
		return $shifted->format( \DateTimeInterface::ATOM );
	}

	/**
	 * Convert a MySQL `Y-m-d H:i:s` UTC timestamp to RFC 3339 / ISO 8601
	 * with a `Z` suffix. Falls back to the raw string when unparseable.
	 */
	private function mysql_to_iso( string $mysql_datetime ): string {
		$timestamp = strtotime( $mysql_datetime . ' UTC' );
		if ( false === $timestamp ) {
			return $mysql_datetime;
		}
		return gmdate( 'Y-m-d\TH:i:s\Z', $timestamp );
	}
}
