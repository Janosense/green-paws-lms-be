<?php

declare(strict_types=1);

namespace VLJwtAuth\Api;

use VLJwtAuth\Auth\AuthFacade;
use VLJwtAuth\Auth\TokenService;
use VLJwtAuth\Exception\TokenException;
use VLJwtAuth\Repository\RefreshTokenRepository;
use VLJwtAuth\Support\CookieManager;
use VLJwtAuth\Support\Hasher;
use VLJwtAuth\Support\OriginGuard;
use VLJwtAuth\Support\RateLimiter;
use VLJwtAuth\Support\Settings;
use WP_REST_Request;
use WP_REST_Response;
use WP_User;

/**
 * REST endpoints under `vl-auth/v1`.
 *
 * Endpoints return a consistent success/error envelope regardless of
 * WordPress's default WP_Error shape. Authentication for protected
 * endpoints happens inside the handler via {@see require_user()} so the
 * envelope is preserved on failure — permission callbacks are reserved
 * for the public PHP API exposed in chunk 4.
 */
final class RestController {

	public const REST_NAMESPACE = 'vl-auth/v1';

	public function __construct(
		private TokenService $token_service,
		private RefreshTokenRepository $refresh_repo,
		private CookieManager $cookies,
		private RateLimiter $rate_limiter,
		private OriginGuard $origin_guard,
		private Settings $settings
	) {
	}

	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/token',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'login' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'username' => [
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

		register_rest_route(
			self::REST_NAMESPACE,
			'/token/refresh',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'refresh' ],
				'permission_callback' => '__return_true',
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/token/validate',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'validate' ],
				'permission_callback' => '__return_true',
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/logout',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'logout' ],
				'permission_callback' => '__return_true',
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/me',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'me' ],
				'permission_callback' => '__return_true',
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/sessions',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'list_sessions' ],
					'permission_callback' => '__return_true',
				],
				[
					'methods'             => 'DELETE',
					'callback'            => [ $this, 'revoke_other_sessions' ],
					'permission_callback' => '__return_true',
				],
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/sessions/(?P<id>\d+)',
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'revoke_session' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'id' => [
						'type'     => 'integer',
						'required' => true,
					],
				],
			]
		);
	}

	public function login( WP_REST_Request $request ): WP_REST_Response {
		$login    = trim( (string) $request->get_param( 'username' ) );
		$password = (string) $request->get_param( 'password' );

		if ( '' === $login || '' === $password ) {
			return $this->error( 'invalid_credentials', __( 'Username/email and password are required.', 'vl-jwt-auth' ), 401 );
		}

		$ip = $this->client_ip() ?? 'anonymous';
		if ( ! $this->rate_limiter->check(
			'login:' . $ip,
			$this->settings->rate_limit_login(),
			$this->settings->rate_limit_window()
		) ) {
			return $this->error( 'rate_limit_exceeded', __( 'Too many login attempts. Try again later.', 'vl-jwt-auth' ), 429 );
		}

		// wp_authenticate runs the default username and email authenticators,
		// so either a login or an email address works transparently.
		$user = wp_authenticate( $login, $password );
		if ( is_wp_error( $user ) || ! $user instanceof WP_User ) {
			[ $code, $message ] = $this->translate_login_error( $user );
			return $this->error( $code, $message, 401 );
		}

		$access  = $this->token_service->issue( $user, 'access' );
		$refresh = $this->token_service->issue( $user, 'refresh' );
		$family  = wp_generate_uuid4();

		$this->refresh_repo->create(
			user_id: (int) $user->ID,
			token: $refresh['token'],
			token_family: $family,
			expires_at_utc: gmdate( 'Y-m-d H:i:s', $refresh['expires_at'] ),
			device_name: $this->guess_device_name( $request ),
			ip_address: $this->client_ip(),
			user_agent: $this->user_agent( $request ),
		);

		$this->cookies->set( $refresh['token'], $refresh['expires_at'] );

		/**
		 * Fires after a user has been authenticated via /token or /token/refresh.
		 *
		 * @param WP_User         $user    The authenticated user.
		 * @param WP_REST_Request $request The originating REST request.
		 */
		do_action( 'vl_jwt_auth_user_authenticated', $user, $request );

		return $this->success(
			[
				'access_token' => $access['token'],
				'token_type'   => 'Bearer',
				'expires_in'   => max( 0, $access['expires_at'] - time() ),
				'user'         => $this->user_payload( $user ),
			]
		);
	}

	public function refresh( WP_REST_Request $request ): WP_REST_Response {
		if ( ! $this->origin_guard->is_allowed( $request ) ) {
			return $this->error( 'invalid_origin', __( 'Request origin is not allowed.', 'vl-jwt-auth' ), 403 );
		}

		$ip            = $this->client_ip() ?? 'anonymous';
		$refresh_limit = (int) apply_filters( 'vl_jwt_auth_rate_limit_refresh', 30 );
		$refresh_win   = (int) apply_filters( 'vl_jwt_auth_rate_limit_refresh_window', 60 );
		if ( ! $this->rate_limiter->check( 'refresh:' . $ip, max( 1, $refresh_limit ), max( 1, $refresh_win ) ) ) {
			return $this->error( 'rate_limit_exceeded', __( 'Too many refresh attempts. Try again later.', 'vl-jwt-auth' ), 429 );
		}

		$cookie_token = $this->cookies->read();
		if ( null === $cookie_token ) {
			return $this->error( 'refresh_token_invalid', __( 'No refresh token provided.', 'vl-jwt-auth' ), 401 );
		}

		$row = $this->refresh_repo->find_by_hash( Hasher::hash( $cookie_token ) );
		if ( null === $row ) {
			$this->cookies->clear();
			return $this->error( 'refresh_token_invalid', __( 'Refresh token is not recognized.', 'vl-jwt-auth' ), 401 );
		}

		// Reuse detection: a revoked row being presented means either an attacker is replaying
		// a token, or the legitimate client retried after rotation. In either case, the safe
		// response is to revoke the whole family and force re-authentication.
		if ( null !== $row['revoked_at'] ) {
			$this->refresh_repo->revoke_family( $row['token_family'] );
			$this->cookies->clear();
			return $this->error( 'refresh_token_reused', __( 'Refresh token replay detected.', 'vl-jwt-auth' ), 401 );
		}

		try {
			$claims = $this->token_service->decode_refresh( $cookie_token );
		} catch ( TokenException $e ) {
			$this->refresh_repo->revoke( $row['id'] );
			$this->cookies->clear();
			return $this->error( $e->error_code(), $e->getMessage(), $e->status_code() );
		}

		$user = get_user_by( 'id', (int) ( $claims['user_id'] ?? 0 ) );
		if ( ! $user instanceof WP_User ) {
			$this->refresh_repo->revoke( $row['id'] );
			$this->cookies->clear();
			return $this->error( 'user_not_found', __( 'User no longer exists.', 'vl-jwt-auth' ), 401 );
		}

		// Rotate: revoke the presented token and issue a new pair under the same family.
		$this->refresh_repo->revoke( $row['id'] );

		$access      = $this->token_service->issue( $user, 'access' );
		$new_refresh = $this->token_service->issue( $user, 'refresh' );

		$this->refresh_repo->create(
			user_id: (int) $user->ID,
			token: $new_refresh['token'],
			token_family: $row['token_family'],
			expires_at_utc: gmdate( 'Y-m-d H:i:s', $new_refresh['expires_at'] ),
			device_name: $row['device_name'] ?? $this->guess_device_name( $request ),
			ip_address: $this->client_ip(),
			user_agent: $this->user_agent( $request ),
		);

		$this->cookies->set( $new_refresh['token'], $new_refresh['expires_at'] );

		/** This filter is documented in {@see self::login()}. */
		do_action( 'vl_jwt_auth_user_authenticated', $user, $request );

		return $this->success(
			[
				'access_token' => $access['token'],
				'token_type'   => 'Bearer',
				'expires_in'   => max( 0, $access['expires_at'] - time() ),
				'user'         => $this->user_payload( $user ),
			]
		);
	}

	public function validate( WP_REST_Request $request ): WP_REST_Response {
		$token = $this->bearer_from_request( $request );
		if ( null === $token ) {
			return $this->error( 'token_invalid', __( 'No access token provided.', 'vl-jwt-auth' ), 401 );
		}

		try {
			$claims = $this->token_service->decode_access( $token );
		} catch ( TokenException $e ) {
			return $this->error( $e->error_code(), $e->getMessage(), $e->status_code() );
		}

		return $this->success(
			[
				'valid'      => true,
				'user_id'    => (int) ( $claims['user_id'] ?? 0 ),
				'expires_at' => (int) ( $claims['exp'] ?? 0 ),
			]
		);
	}

	public function logout( WP_REST_Request $request ): WP_REST_Response {
		if ( ! $this->origin_guard->is_allowed( $request ) ) {
			return $this->error( 'invalid_origin', __( 'Request origin is not allowed.', 'vl-jwt-auth' ), 403 );
		}

		$cookie_token = $this->cookies->read();
		if ( null !== $cookie_token ) {
			$row = $this->refresh_repo->find_by_hash( Hasher::hash( $cookie_token ) );
			if ( null !== $row && null === $row['revoked_at'] ) {
				$this->refresh_repo->revoke( $row['id'] );
			}
		}

		$this->cookies->clear();
		return $this->success( [ 'logged_out' => true ] );
	}

	public function me( WP_REST_Request $request ): WP_REST_Response {
		$user = $this->require_user( $request );
		if ( $user instanceof WP_REST_Response ) {
			return $user;
		}
		return $this->success( $this->user_payload( $user, true ) );
	}

	public function list_sessions( WP_REST_Request $request ): WP_REST_Response {
		$user = $this->require_user( $request );
		if ( $user instanceof WP_REST_Response ) {
			return $user;
		}

		$current_hash = null;
		$cookie       = $this->cookies->read();
		if ( null !== $cookie ) {
			$current_hash = Hasher::hash( $cookie );
		}

		$rows     = $this->refresh_repo->list_active_for_user( (int) $user->ID );
		$sessions = array_map(
			static function ( array $row ) use ( $current_hash ): array {
				return [
					'id'           => $row['id'],
					'device_name'  => '' !== (string) $row['device_name'] ? $row['device_name'] : null,
					'ip_address'   => $row['ip_address'],
					'user_agent'   => '' !== (string) $row['user_agent'] ? $row['user_agent'] : null,
					'created_at'   => $row['created_at'],
					'last_used_at' => $row['last_used_at'],
					'expires_at'   => $row['expires_at'],
					'current'      => null !== $current_hash && $row['token_hash'] === $current_hash,
				];
			},
			$rows
		);

		return $this->success( [ 'sessions' => array_values( $sessions ) ] );
	}

	public function revoke_session( WP_REST_Request $request ): WP_REST_Response {
		$user = $this->require_user( $request );
		if ( $user instanceof WP_REST_Response ) {
			return $user;
		}

		$id  = (int) $request->get_param( 'id' );
		$row = $this->refresh_repo->find( $id );
		// Don't leak whether a foreign session exists — treat "not yours" as 404.
		if ( null === $row || $row['user_id'] !== (int) $user->ID ) {
			return $this->error( 'not_found', __( 'Session not found.', 'vl-jwt-auth' ), 404 );
		}

		$this->refresh_repo->revoke( $id );

		// If the caller revoked their *own* current session, clear the cookie too.
		$cookie = $this->cookies->read();
		if ( null !== $cookie && Hasher::hash( $cookie ) === $row['token_hash'] ) {
			$this->cookies->clear();
		}

		return $this->success( [ 'revoked' => true ] );
	}

	/**
	 * Bulk-revoke every active refresh-token row for the current user
	 * **except** the one tied to the refresh cookie on this request.
	 *
	 * Requires both bearer auth and a valid refresh cookie. The double
	 * requirement prevents an attacker holding only a stolen access token
	 * from logging the legitimate user out of every other device. The
	 * origin guard is applied because this is a state-changing,
	 * cookie-backed, cross-origin-reachable endpoint.
	 */
	public function revoke_other_sessions( WP_REST_Request $request ): WP_REST_Response {
		if ( ! $this->origin_guard->is_allowed( $request ) ) {
			return $this->error( 'invalid_origin', __( 'Request origin is not allowed.', 'vl-jwt-auth' ), 403 );
		}

		$user = $this->require_user( $request );
		if ( $user instanceof WP_REST_Response ) {
			return $user;
		}

		$cookie_token = $this->cookies->read();
		if ( null === $cookie_token ) {
			return $this->error( 'refresh_token_required', __( 'A refresh cookie is required to identify the current session.', 'vl-jwt-auth' ), 401 );
		}

		$current_hash = Hasher::hash( $cookie_token );
		$row          = $this->refresh_repo->find_by_hash( $current_hash );
		// The current session must be live AND owned by the bearer user.
		// Anything else is treated as an invalid current cookie — never
		// touch other sessions when we cannot prove which one to keep.
		if ( null === $row || $row['user_id'] !== (int) $user->ID || null !== $row['revoked_at'] ) {
			return $this->error( 'refresh_token_invalid', __( 'Refresh token is not recognized.', 'vl-jwt-auth' ), 401 );
		}

		$revoked = $this->refresh_repo->revoke_user_except( (int) $user->ID, $current_hash );

		return $this->success( [ 'revoked_count' => $revoked ] );
	}

	/**
	 * Resolve the authenticated user, or return a ready-to-send error response
	 * in this plugin's envelope shape.
	 *
	 * Kept as a local helper (rather than using Middleware directly) so the
	 * plugin's own endpoints return {success: false, error: {...}} instead
	 * of WordPress's default WP_Error rendering.
	 */
	private function require_user( WP_REST_Request $request ): WP_User|WP_REST_Response {
		$token = $this->bearer_from_request( $request );
		if ( null === $token ) {
			return $this->error( 'token_invalid', __( 'Authentication required.', 'vl-jwt-auth' ), 401 );
		}

		try {
			$claims = AuthFacade::decode_access_token( $token );
		} catch ( TokenException $e ) {
			return $this->error( $e->error_code(), $e->getMessage(), $e->status_code() );
		}

		$user = get_user_by( 'id', (int) ( $claims['user_id'] ?? 0 ) );
		if ( ! $user instanceof WP_User ) {
			return $this->error( 'user_not_found', __( 'User no longer exists.', 'vl-jwt-auth' ), 401 );
		}

		return $user;
	}

	private function bearer_from_request( WP_REST_Request $request ): ?string {
		$header = (string) $request->get_header( 'authorization' );
		if ( '' === $header ) {
			return null;
		}
		if ( preg_match( '/^Bearer\s+(\S+)/i', $header, $matches ) ) {
			return $matches[1];
		}
		return null;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function user_payload( WP_User $user, bool $include_caps = false ): array {
		$payload = [
			'id'           => (int) $user->ID,
			'login'        => $user->user_login,
			'email'        => $user->user_email,
			'display_name' => $user->display_name,
			'roles'        => array_values( (array) $user->roles ),
		];
		if ( $include_caps ) {
			$payload['capabilities'] = array_values(
				array_keys( array_filter( (array) $user->allcaps ) )
			);
		}
		return $payload;
	}

	private function client_ip(): ?string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) )
			: '';

		/**
		 * Filter the client IP used when persisting refresh-token rows.
		 * Plugins running behind a reverse proxy should hook here to read
		 * from a trusted forwarded-for header.
		 *
		 * @param string $ip The IP derived from REMOTE_ADDR (may be empty).
		 */
		$ip = (string) apply_filters( 'vl_jwt_auth_client_ip', $ip );

		return '' !== $ip && false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : null;
	}

	private function user_agent( WP_REST_Request $request ): ?string {
		$ua = (string) $request->get_header( 'user_agent' );
		return '' !== $ua ? substr( $ua, 0, 500 ) : null;
	}

	private function guess_device_name( WP_REST_Request $request ): ?string {
		$ua = $this->user_agent( $request );
		return null === $ua ? null : substr( $ua, 0, 191 );
	}

	/**
	 * Translate a `wp_authenticate()` failure into the response envelope's
	 * `(code, message)` pair, applying the login error passthrough whitelist.
	 *
	 * Whitelist (see CLAUDE.md → "Login error passthrough contract"):
	 *   - Core WP authenticator codes: invalid_username, invalid_email,
	 *     incorrect_password, empty_username, empty_password.
	 *   - Any code matching the prefix `vl_` (e.g. `vl_lms_email_not_verified`),
	 *     so first-party plugins hooking `wp_authenticate_user` can surface
	 *     a distinct reason without coupling this plugin to LMS details.
	 *
	 * Anything else collapses to `invalid_credentials` with a generic message,
	 * preventing arbitrary third-party plugins from leaking internal state
	 * (or unprintable codes) through the public envelope.
	 *
	 * @param mixed $error The value returned by `wp_authenticate()`.
	 * @return array{0: string, 1: string} Tuple of (code, message).
	 */
	private function translate_login_error( mixed $error ): array {
		$generic_message = __( 'Invalid username or password.', 'vl-jwt-auth' );

		if ( ! is_wp_error( $error ) ) {
			return [ 'invalid_credentials', $generic_message ];
		}

		$code    = (string) $error->get_error_code();
		$message = (string) $error->get_error_message();

		$allowed = [
			'invalid_username',
			'invalid_email',
			'incorrect_password',
			'empty_username',
			'empty_password',
		];

		$is_allowed = in_array( $code, $allowed, true ) || str_starts_with( $code, 'vl_' );
		if ( ! $is_allowed ) {
			return [ 'invalid_credentials', $generic_message ];
		}

		// `WP_Error` messages may contain HTML (core's `incorrect_password`
		// embeds an anchor to the lost-password screen). Strip tags so the
		// JSON envelope stays plain text for SPA consumers.
		$message = trim( wp_strip_all_tags( $message ) );
		if ( '' === $message ) {
			$message = $generic_message;
		}

		return [ $code, $message ];
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function success( array $data, int $status = 200 ): WP_REST_Response {
		return new WP_REST_Response(
			[
				'success' => true,
				'data'    => $data,
			],
			$status
		);
	}

	private function error( string $code, string $message, int $status ): WP_REST_Response {
		return new WP_REST_Response(
			[
				'success' => false,
				'error'   => [
					'code'    => $code,
					'message' => $message,
					'status'  => $status,
				],
			],
			$status
		);
	}
}
