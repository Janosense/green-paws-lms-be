<?php

declare(strict_types=1);

namespace VL\LMS\Api;

use VL\LMS\Api\Transformers\WebinarRegistrationTransformer;
use VL\LMS\Auth\RestAuthenticator;
use VL\LMS\Domain\WebinarRegistration\WebinarRegistrationSource;
use VL\LMS\Domain\WebinarRegistration\WebinarRegistrationStatus;
use VL\LMS\Repositories\WebinarRegistrationRepository;
use VL\LMS\Services\Webinars\WebinarLookup;
use VL\LMS\Services\Webinars\WebinarRegistrationDecision;
use VL\LMS\Services\Webinars\WebinarRegistrationDecisionType;
use VL\LMS\Services\Webinars\WebinarRegistrationError;
use VL\LMS\Services\Webinars\WebinarRegistrationService;
use Closure;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_User;

/**
 * REST controller for the three Phase 7.3 registration endpoints.
 *
 * - `POST   /vl/v1/webinars/{slug}/registrations` — self-register.
 * - `GET    /vl/v1/webinars/me`                   — list mine.
 * - `DELETE /vl/v1/webinars/{slug}/registrations` — cancel mine.
 *
 * All three require JWT auth (`current_user_can('vl_register_for_webinar')`
 * for the slug routes; the `me` route requires only authentication).
 *
 * Validation lives in {@see WebinarRegistrationService}; this controller
 * is a thin REST adapter that maps decisions to HTTP.
 *
 * @author Tymofii Synianskyi
 */
final class WebinarRegistrationsController {

	public const string REGISTRATIONS_ROUTE = '/webinars/(?P<slug>[a-zA-Z0-9-]+)/registrations';
	public const string ME_ROUTE            = '/webinars/me';

	public const string REGISTER_CAPABILITY = 'vl_register_for_webinar';

	/** @var Closure(): \DateTimeImmutable */
	private readonly Closure $clock;

	/**
	 * @param Closure(): \DateTimeImmutable $clock
	 */
	public function __construct(
		private readonly string $rest_namespace,
		private readonly RestAuthenticator $authenticator,
		private readonly WebinarRegistrationService $service,
		private readonly WebinarLookup $lookup,
		private readonly WebinarRegistrationRepository $repository,
		private readonly WebinarRegistrationTransformer $transformer,
		Closure $clock,
	) {
		$this->clock = $clock;
	}

	public function register_routes(): void {
		register_rest_route(
			$this->rest_namespace,
			self::REGISTRATIONS_ROUTE,
			[
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'register' ],
					'permission_callback' => [ $this, 'permission_register' ],
				],
				[
					'methods'             => 'DELETE',
					'callback'            => [ $this, 'cancel' ],
					'permission_callback' => [ $this, 'permission_register' ],
				],
			]
		);

		register_rest_route(
			$this->rest_namespace,
			self::ME_ROUTE,
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'list_mine' ],
				'permission_callback' => [ $this, 'permission_list_mine' ],
			]
		);
	}

	/**
	 * @return bool|WP_Error
	 */
	public function permission_register( WP_REST_Request $request ) {
		$user = $this->authenticator->user_from_request( $request );
		if ( ! $user instanceof WP_User ) {
			return $this->not_logged_in();
		}
		if ( ! $user->has_cap( self::REGISTER_CAPABILITY ) ) {
			return $this->forbidden();
		}
		return true;
	}

	/**
	 * @return bool|WP_Error
	 */
	public function permission_list_mine( WP_REST_Request $request ) {
		$user = $this->authenticator->user_from_request( $request );
		if ( ! $user instanceof WP_User ) {
			return $this->not_logged_in();
		}
		return true;
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function register( WP_REST_Request $request ) {
		$user = $this->authenticator->user_from_request( $request );
		if ( ! $user instanceof WP_User ) {
			return $this->not_logged_in();
		}
		$user_id = (int) $user->ID;
		$slug    = (string) $request->get_param( 'slug' );

		$decision = $this->service->register( $user_id, $slug, WebinarRegistrationSource::SELF_SIGNUP );
		if ( WebinarRegistrationDecisionType::FAILED === $decision->decision ) {
			return $this->map_register_error( $decision );
		}

		$webinar = $this->lookup->find_by_slug( $slug );
		if ( ! $webinar instanceof WP_Post || null === $decision->registration ) {
			return $this->webinar_not_found();
		}

		$payload = [
			'success'      => true,
			'registration' => $this->transformer->transform( $decision->registration, $webinar, ( $this->clock )() ),
		];
		if ( WebinarRegistrationDecisionType::ALREADY_ACTIVE === $decision->decision ) {
			$payload['idempotent'] = true;
		}

		$response = rest_ensure_response( $payload );
		$status   = WebinarRegistrationDecisionType::REGISTERED === $decision->decision ? 201 : 200;
		$response->set_status( $status );
		return $response;
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function cancel( WP_REST_Request $request ) {
		$user = $this->authenticator->user_from_request( $request );
		if ( ! $user instanceof WP_User ) {
			return $this->not_logged_in();
		}
		$user_id = (int) $user->ID;
		$slug    = (string) $request->get_param( 'slug' );

		$decision = $this->service->cancel( $user_id, $slug );
		if ( WebinarRegistrationDecisionType::FAILED === $decision->decision ) {
			return $this->map_cancel_error( $decision );
		}

		$webinar = $this->lookup->find_by_slug( $slug );
		if ( ! $webinar instanceof WP_Post || null === $decision->registration ) {
			return $this->webinar_not_found();
		}

		$payload = [
			'success'      => true,
			'registration' => $this->transformer->transform( $decision->registration, $webinar, ( $this->clock )() ),
		];
		if ( WebinarRegistrationDecisionType::ALREADY_CANCELLED === $decision->decision ) {
			$payload['idempotent'] = true;
		}
		return rest_ensure_response( $payload );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_mine( WP_REST_Request $request ) {
		$user = $this->authenticator->user_from_request( $request );
		if ( ! $user instanceof WP_User ) {
			return $this->not_logged_in();
		}
		$user_id = (int) $user->ID;

		$status_param = strtolower( (string) $request->get_param( 'status' ) );
		if ( '' === $status_param ) {
			$status_param = 'active';
		}
		$status_filter = match ( $status_param ) {
			'active'    => WebinarRegistrationStatus::ACTIVE,
			'cancelled' => WebinarRegistrationStatus::CANCELLED,
			'all'       => null,
			default     => WebinarRegistrationStatus::ACTIVE,
		};

		$time_filter = strtolower( (string) $request->get_param( 'time_filter' ) );
		if ( '' === $time_filter ) {
			$time_filter = 'upcoming';
		}
		if ( ! in_array( $time_filter, [ 'upcoming', 'past', 'all' ], true ) ) {
			$time_filter = 'upcoming';
		}

		$registrations = $this->repository->list_for_user( $user_id, $status_filter );
		if ( [] === $registrations ) {
			return rest_ensure_response(
				[
					'success'       => true,
					'registrations' => [],
				]
			);
		}

		$webinar_ids = [];
		foreach ( $registrations as $registration ) {
			$webinar_ids[] = $registration->webinar_id;
		}

		$webinars = $this->load_webinars_by_ids( $webinar_ids );

		$now   = ( $this->clock )();
		$items = [];
		foreach ( $registrations as $registration ) {
			$webinar = $webinars[ $registration->webinar_id ] ?? null;
			if ( ! $webinar instanceof WP_Post ) {
				continue;
			}

			if ( ! $this->matches_time_filter( $webinar, $now, $time_filter ) ) {
				continue;
			}

			$items[] = [
				'_sort'   => $this->scheduled_start_unix( $webinar ),
				'payload' => $this->transformer->transform( $registration, $webinar, $now ),
			];
		}

		usort(
			$items,
			static function ( array $a, array $b ) use ( $time_filter ): int {
				$cmp = $a['_sort'] <=> $b['_sort'];
				return 'past' === $time_filter ? -$cmp : $cmp;
			}
		);

		$payloads = array_map(
			static fn ( array $item ): array => $item['payload'],
			$items
		);

		return rest_ensure_response(
			[
				'success'       => true,
				'registrations' => $payloads,
			]
		);
	}

	/**
	 * @param list<int> $webinar_ids
	 * @return array<int, WP_Post>
	 */
	private function load_webinars_by_ids( array $webinar_ids ): array {
		$webinar_ids = array_values( array_unique( array_filter( $webinar_ids, static fn ( int $id ): bool => $id > 0 ) ) );
		if ( [] === $webinar_ids ) {
			return [];
		}

		$out = [];
		foreach ( $webinar_ids as $id ) {
			$post = get_post( $id );
			if ( $post instanceof WP_Post && 'vl_webinar' === $post->post_type ) {
				$out[ $id ] = $post;
			}
		}
		return $out;
	}

	private function matches_time_filter( WP_Post $webinar, \DateTimeImmutable $now, string $time_filter ): bool {
		if ( 'all' === $time_filter ) {
			return true;
		}
		$end_raw = (string) get_post_meta( (int) $webinar->ID, '_vl_webinar_scheduled_end', true );
		try {
			$end = '' === $end_raw ? null : new \DateTimeImmutable( $end_raw );
		} catch ( \Throwable ) {
			$end = null;
		}
		if ( null === $end ) {
			// Treat unschedulable rows as upcoming so they do not silently
			// disappear from the list.
			return 'upcoming' === $time_filter;
		}
		return 'past' === $time_filter ? $now > $end : $now <= $end;
	}

	private function scheduled_start_unix( WP_Post $webinar ): int {
		$start_raw = (string) get_post_meta( (int) $webinar->ID, '_vl_webinar_scheduled_start', true );
		if ( '' === $start_raw ) {
			return PHP_INT_MAX;
		}
		try {
			return ( new \DateTimeImmutable( $start_raw ) )->getTimestamp();
		} catch ( \Throwable ) {
			return PHP_INT_MAX;
		}
	}

	private function map_register_error( WebinarRegistrationDecision $decision ): WP_Error {
		$error = $decision->error;
		if ( null === $error ) {
			return new WP_Error( 'webinar_registration_failed', 'Registration failed.', [ 'status' => 500 ] );
		}
		switch ( $error ) {
			case WebinarRegistrationError::WEBINAR_NOT_FOUND:
			case WebinarRegistrationError::NOT_PUBLISHED:
				return new WP_Error(
					'webinar_not_found',
					__( 'Webinar not found.', 'vl-lms' ),
					[ 'status' => 404 ]
				);
			case WebinarRegistrationError::REGISTRATION_NOT_OPEN_YET:
				return new WP_Error(
					'registration_not_open_yet',
					__( 'Registration has not opened yet.', 'vl-lms' ),
					array_merge( [ 'status' => 409 ], $decision->context )
				);
			case WebinarRegistrationError::REGISTRATION_CLOSED:
				return new WP_Error(
					'registration_closed',
					__( 'Registration is closed.', 'vl-lms' ),
					array_merge( [ 'status' => 409 ], $decision->context )
				);
			case WebinarRegistrationError::PAYMENT_REQUIRED:
				return new WP_Error(
					'payment_required',
					__( 'Webinar requires payment.', 'vl-lms' ),
					array_merge( [ 'status' => 402 ], $decision->context )
				);
			case WebinarRegistrationError::CAPACITY_REACHED:
				return new WP_Error(
					'capacity_reached',
					__( 'Webinar has reached capacity.', 'vl-lms' ),
					array_merge( [ 'status' => 409 ], $decision->context )
				);
			default:
				return new WP_Error( 'webinar_registration_failed', 'Registration failed.', [ 'status' => 500 ] );
		}
	}

	private function map_cancel_error( WebinarRegistrationDecision $decision ): WP_Error {
		$error = $decision->error;
		if ( WebinarRegistrationError::WEBINAR_NOT_FOUND === $error ) {
			return new WP_Error(
				'webinar_not_found',
				__( 'Webinar not found.', 'vl-lms' ),
				[ 'status' => 404 ]
			);
		}
		return new WP_Error(
			'not_registered',
			__( 'You are not registered for this webinar.', 'vl-lms' ),
			[ 'status' => 409 ]
		);
	}

	private function not_logged_in(): WP_Error {
		return new WP_Error(
			'rest_not_logged_in',
			__( 'You are not currently logged in.', 'vl-lms' ),
			[ 'status' => 401 ]
		);
	}

	private function forbidden(): WP_Error {
		return new WP_Error(
			'rest_forbidden',
			__( 'You do not have permission to register for webinars.', 'vl-lms' ),
			[ 'status' => 403 ]
		);
	}

	private function webinar_not_found(): WP_Error {
		return new WP_Error(
			'webinar_not_found',
			__( 'Webinar not found.', 'vl-lms' ),
			[ 'status' => 404 ]
		);
	}
}
