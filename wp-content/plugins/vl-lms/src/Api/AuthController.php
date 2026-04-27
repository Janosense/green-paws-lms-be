<?php

declare(strict_types=1);

namespace VL\LMS\Api;

use VL\LMS\Auth\AccountKind;
use VL\LMS\Auth\PasswordReset\PasswordResetConfirmRequest;
use VL\LMS\Auth\PasswordReset\PasswordResetException;
use VL\LMS\Auth\PasswordReset\PasswordResetRequest;
use VL\LMS\Auth\PasswordReset\PasswordResetService;
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
 * Five public endpoints:
 * - `POST /vl/v1/auth/register`                — create an account (idempotent, generic response).
 * - `POST /vl/v1/auth/verify-email`            — consume a verification token, issue JWTs.
 * - `POST /vl/v1/auth/resend-verification`     — re-send the verification email (rate-limited, generic response).
 * - `POST /vl/v1/auth/request-password-reset`  — start a password reset (rate-limited, generic response).
 * - `POST /vl/v1/auth/reset-password`          — consume a reset token and update the password.
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

	public const string REQUEST_PASSWORD_RESET_ROUTE = '/auth/request-password-reset';

	public const string RESET_PASSWORD_ROUTE = '/auth/reset-password';

	public function __construct(
		private readonly string $rest_namespace,
		private readonly RegistrationService $registration,
		private readonly EmailVerificationService $verification,
		private readonly TokenIssuer $token_issuer,
		private readonly PasswordResetService $password_reset
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

		register_rest_route(
			$this->rest_namespace,
			self::REQUEST_PASSWORD_RESET_ROUTE,
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'request_password_reset' ],
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

		register_rest_route(
			$this->rest_namespace,
			self::RESET_PASSWORD_ROUTE,
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'reset_password' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'token'    => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
					'password' => [
						'type'     => 'string',
						'required' => true,
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
	 * `POST /vl/v1/auth/request-password-reset`.
	 *
	 * Always returns the same generic body. Rate-limit hits are absorbed
	 * by the service too — a repeated-targeting attacker cannot tell
	 * from the response whether the mail was actually sent.
	 *
	 * @return WP_REST_Response
	 */
	public function request_password_reset( WP_REST_Request $request ): WP_REST_Response {
		$this->password_reset->request(
			new PasswordResetRequest(
				email: (string) $request->get_param( 'email' ),
				ip: $this->client_ip(),
			)
		);

		return rest_ensure_response(
			[
				'success' => true,
				'data'    => [
					'message' => __(
						'If an account exists for this email, a reset link has been sent.',
						'vl-lms'
					),
				],
			]
		);
	}

	/**
	 * `POST /vl/v1/auth/reset-password`.
	 *
	 * Consumes the reset token and applies the new password. WP core's
	 * `reset_password()` cascades refresh-token revocation through
	 * `vl-jwt-auth`.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function reset_password( WP_REST_Request $request ) {
		try {
			$this->password_reset->confirm(
				new PasswordResetConfirmRequest(
					token: (string) $request->get_param( 'token' ),
					password: (string) $request->get_param( 'password' )
				)
			);
		} catch ( PasswordResetException $e ) {
			return new WP_Error(
				$e->error_code(),
				$e->getMessage(),
				[ 'status' => $e->status_code() ]
			);
		}

		return rest_ensure_response(
			[
				'success' => true,
				'data'    => [
					'message' => __(
						'Password updated. You can now sign in.',
						'vl-lms'
					),
				],
			]
		);
	}

	/**
	 * Best-effort client IP extraction for rate limiting.
	 *
	 * Plain `$_SERVER['REMOTE_ADDR']` is fine in the common case; reverse
	 * proxies that prepend `X-Forwarded-For` are out of scope for this
	 * endpoint — the rate limit is a spam-throttle, not a security
	 * boundary, and `vl-jwt-auth` already has a filter seam
	 * (`vl_jwt_auth_client_ip`) for more sophisticated IP handling on its
	 * login routes.
	 */
	private function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
		return sanitize_text_field( $ip );
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
