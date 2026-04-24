<?php

declare(strict_types=1);

namespace VL\LMS\Api;

use VL\LMS\Auth\AccountKind;
use VL\LMS\Auth\Registration\RegistrationException;
use VL\LMS\Auth\Registration\RegistrationRequest;
use VL\LMS\Auth\Registration\RegistrationService;
use VL\LMS\Auth\TokenIssuer;
use VL\LMS\Auth\Verification\EmailVerificationService;
use VL\LMS\Auth\Verification\VerificationException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_User;

/**
 * REST controller for `/vl/v1/auth/*`.
 *
 * Three public endpoints:
 * - `POST /vl/v1/auth/register`             — create an account (idempotent, generic response).
 * - `POST /vl/v1/auth/verify-email`         — consume a token, issue JWTs.
 * - `POST /vl/v1/auth/resend-verification`  — re-send the verification email (rate-limited, generic response).
 *
 * Success envelope: `{ success: true, data: {...} }`.
 * Error shape: `WP_Error`, rendered by WordPress as
 * `{ code, message, data: { status } }` (the project-standard error
 * shape — distinct from `vl-jwt-auth`'s own `{ success: false, error }`).
 *
 * @author Tymofii Synianskyi
 */
final class AuthController {

	public const string REGISTER_ROUTE = '/auth/register';

	public const string VERIFY_ROUTE = '/auth/verify-email';

	public const string RESEND_ROUTE = '/auth/resend-verification';

	public function __construct(
		private readonly string $rest_namespace,
		private readonly RegistrationService $registration,
		private readonly EmailVerificationService $verification,
		private readonly TokenIssuer $token_issuer
	) {
	}

	public function register_routes(): void {
		register_rest_route(
			$this->rest_namespace,
			self::REGISTER_ROUTE,
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'register' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'email'        => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_email',
					],
					'password'     => [
						'type'     => 'string',
						'required' => true,
					],
					'first_name'   => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
					'last_name'    => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
					'account_kind' => [
						'type'              => 'string',
						'required'          => false,
						'default'           => AccountKind::STUDENT,
						'enum'              => AccountKind::ALLOWED,
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);

		register_rest_route(
			$this->rest_namespace,
			self::VERIFY_ROUTE,
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'verify_email' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'token' => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);

		register_rest_route(
			$this->rest_namespace,
			self::RESEND_ROUTE,
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'resend_verification' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'email' => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_email',
					],
				],
			]
		);
	}

	/**
	 * `POST /vl/v1/auth/register`.
	 *
	 * Returns the same generic 201 response regardless of whether a new
	 * account was created, a resend was triggered, or the email was
	 * already verified. That prevents email enumeration.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function register( WP_REST_Request $request ) {
		try {
			$registration_request = new RegistrationRequest(
				email: (string) $request->get_param( 'email' ),
				password: (string) $request->get_param( 'password' ),
				first_name: (string) $request->get_param( 'first_name' ),
				last_name: (string) $request->get_param( 'last_name' ),
				account_kind: (string) ( $request->get_param( 'account_kind' ) ?? AccountKind::STUDENT )
			);
			$this->registration->register( $registration_request );
		} catch ( RegistrationException $e ) {
			return new WP_Error(
				$e->error_code(),
				$e->getMessage(),
				[ 'status' => $e->status_code() ]
			);
		}

		$response = rest_ensure_response(
			[
				'success' => true,
				'data'    => [
					'message' => __(
						'If the account can be created and is not yet verified, a verification email has been sent.',
						'vl-lms'
					),
				],
			]
		);
		$response->set_status( 201 );
		return $response;
	}

	/**
	 * `POST /vl/v1/auth/verify-email`.
	 *
	 * On success, verifies the email and issues a JWT access + refresh
	 * pair via {@see TokenIssuer} so the user lands logged in.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function verify_email( WP_REST_Request $request ) {
		try {
			$user_id = $this->verification->verify( (string) $request->get_param( 'token' ) );
		} catch ( VerificationException $e ) {
			return new WP_Error(
				$e->error_code(),
				$e->getMessage(),
				[ 'status' => $e->status_code() ]
			);
		}

		$user = get_user_by( 'id', $user_id );
		if ( ! $user instanceof WP_User ) {
			return new WP_Error(
				'vl_lms_verification_user_missing',
				__( 'User account no longer exists.', 'vl-lms' ),
				[ 'status' => 410 ]
			);
		}

		$tokens = $this->token_issuer->issue_for( $user, $request );

		return rest_ensure_response(
			[
				'success' => true,
				'data'    => array_merge(
					$tokens,
					[
						'user' => $this->user_payload( $user ),
					]
				),
			]
		);
	}

	/**
	 * `POST /vl/v1/auth/resend-verification`.
	 *
	 * Always returns the same generic body; the service swallows every
	 * branch internally to keep responses indistinguishable.
	 *
	 * @return WP_REST_Response
	 */
	public function resend_verification( WP_REST_Request $request ): WP_REST_Response {
		$this->verification->resend( (string) $request->get_param( 'email' ) );

		return rest_ensure_response(
			[
				'success' => true,
				'data'    => [
					'message' => __(
						'If the account exists and is unverified, an email has been sent.',
						'vl-lms'
					),
				],
			]
		);
	}

	/**
	 * @return array{id: int, email: string, display_name: string, roles: list<string>, account_kind: string}
	 */
	private function user_payload( WP_User $user ): array {
		return [
			'id'           => (int) $user->ID,
			'email'        => (string) $user->user_email,
			'display_name' => (string) $user->display_name,
			'roles'        => array_values( (array) $user->roles ),
			'account_kind' => (string) get_user_meta( (int) $user->ID, '_vl_account_kind', true ),
		];
	}
}
