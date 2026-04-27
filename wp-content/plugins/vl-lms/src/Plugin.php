<?php

declare(strict_types=1);

namespace VL\LMS;

use VL\LMS\Access\InstructorAccessFilter;
use VL\LMS\Access\TableBackedCoInstructorLookup;
use VL\LMS\Api\AuthController;
use VL\LMS\Api\RestController;
use VL\LMS\Auth\JwtBridgeTokenIssuer;
use VL\LMS\Auth\LoginGate\UnverifiedLoginBlocker;
use VL\LMS\Auth\Mail\PasswordResetMailer;
use VL\LMS\Auth\Mail\VerificationMailer;
use VL\LMS\Auth\PasswordPolicy;
use VL\LMS\Auth\PasswordReset\PasswordResetService;
use VL\LMS\Auth\Registration\RegistrationService;
use VL\LMS\Auth\TokenIssuer;
use VL\LMS\Auth\Verification\EmailVerificationService;
use VL\LMS\CPT\CptRegistrar;
use VL\LMS\Repositories\CourseInstructorRepository;
use VL\LMS\Services\CourseInstructors\AuthorSyncService;
use VL\LMS\Support\Logger;
use VL\LMS\Taxonomy\DifficultyTermsInstaller;
use VL\LMS\Taxonomy\TaxonomyRegistrar;
use VLJwtAuth\Support\RateLimiter;

/**
 * Main plugin bootstrap.
 *
 * Verifies the vl-jwt-auth runtime dependency, wires core services into
 * a service locator, and exposes the `vl_lms/booted` action so future
 * modules can hook in without modifying this class.
 */
final class Plugin {

	private static ?self $instance = null;

	/** @var (callable(): bool)|null */
	private static $dependency_checker = null;

	private bool $booted = false;

	private ?Container $container = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	/**
	 * Override the runtime dependency check. Intended for tests only —
	 * PHP cannot unload a class once declared, so the default
	 * `class_exists('\VLJwtAuth\Auth')` cannot be swept between test cases.
	 *
	 * @param (callable(): bool)|null $checker
	 */
	public static function set_dependency_checker( ?callable $checker ): void {
		self::$dependency_checker = $checker;
	}

	private function __construct() {
	}

	/**
	 * Register WordPress hooks and expose the container.
	 *
	 * Idempotent: calling twice in the same request is a no-op after the
	 * first successful boot.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		if ( ! $this->dependencies_met() ) {
			add_action( 'admin_notices', [ $this, 'render_missing_dependency_notice' ] );
			return;
		}

		$this->booted    = true;
		$this->container = $this->build_container();

		add_action( 'init', [ $this, 'load_textdomain' ] );
		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );

		$cpt_registrar = new CptRegistrar();
		$cpt_registrar->register_hooks();

		$taxonomy_registrar = new TaxonomyRegistrar();
		$taxonomy_registrar->register_hooks();

		$course_instructor_repo   = new CourseInstructorRepository();
		$co_instructor_lookup     = new TableBackedCoInstructorLookup( $course_instructor_repo );
		$instructor_access_filter = new InstructorAccessFilter( $co_instructor_lookup );
		$instructor_access_filter->register_hooks();

		$author_sync_service = new AuthorSyncService( $course_instructor_repo );
		$author_sync_service->register_hooks();

		$unverified_login_blocker = $this->container->get( UnverifiedLoginBlocker::class );
		if ( $unverified_login_blocker instanceof UnverifiedLoginBlocker ) {
			$unverified_login_blocker->register_hooks();
		}

		// First-run tasks queued by Activator::activate() — runs AFTER
		// the CPT and taxonomy registrars (both hooked at priority 10)
		// have registered their types on `init`. Registering the
		// listener only when the flag is set means the hook cost is
		// paid exactly on the request that clears it.
		if ( '1' === get_option( Activator::FIRST_RUN_PENDING_OPTION ) ) {
			add_action( 'init', [ $this, 'run_first_run_tasks' ], 20 );
		}

		/**
		 * Fires once the plugin has finished booting.
		 *
		 * Downstream modules register their services, hooks, and routes
		 * against the container here.
		 *
		 * @param Container $container Service locator.
		 */
		do_action( 'vl_lms/booted', $this->container );
	}

	/**
	 * Whether the vl-jwt-auth facade class is available.
	 */
	public function dependencies_met(): bool {
		if ( null !== self::$dependency_checker ) {
			return (bool) ( self::$dependency_checker )();
		}
		return class_exists( '\\VLJwtAuth\\Auth' );
	}

	public function container(): ?Container {
		return $this->container;
	}

	public function load_textdomain(): void {
		load_plugin_textdomain(
			'vl-lms',
			false,
			dirname( VL_LMS_BASENAME ) . '/languages'
		);
	}

	public function register_rest_routes(): void {
		if ( null === $this->container ) {
			return;
		}
		$controller = $this->container->get( RestController::class );
		if ( $controller instanceof RestController ) {
			$controller->register_routes();
		}
		$auth_controller = $this->container->get( AuthController::class );
		if ( $auth_controller instanceof AuthController ) {
			$auth_controller->register_routes();
		}
	}

	/**
	 * First-run tasks that depend on CPTs / taxonomies being registered.
	 *
	 * Runs once, on the first `init` after activation, and deletes the
	 * pending flag so subsequent requests skip the hook registration
	 * entirely. Idempotent — each sub-task checks its own state.
	 */
	public function run_first_run_tasks(): void {
		DifficultyTermsInstaller::install();

		if ( ! wp_doing_cron() && ! wp_installing() ) {
			flush_rewrite_rules( false );
		}

		delete_option( Activator::FIRST_RUN_PENDING_OPTION );
	}

	public function render_missing_dependency_notice(): void {
		echo '<div class="notice notice-error"><p>';
		echo esc_html__(
			'VL LMS requires the VL JWT Auth plugin to be active.',
			'vl-lms'
		);
		echo '</p></div>';
	}

	private function build_container(): Container {
		$container = new Container();

		$container->set(
			Logger::class,
			static fn (): Logger => new Logger( 'vl-lms' )
		);

		$container->set(
			RestController::class,
			static fn (): RestController => new RestController(
				VL_LMS_API_NAMESPACE,
				VL_LMS_VERSION
			)
		);

		$container->set(
			VerificationMailer::class,
			static function ( Container $c ): VerificationMailer {
				$logger = $c->get( Logger::class );
				assert( $logger instanceof Logger );
				return new VerificationMailer( $logger );
			}
		);

		$container->set(
			PasswordResetMailer::class,
			static function ( Container $c ): PasswordResetMailer {
				$logger = $c->get( Logger::class );
				assert( $logger instanceof Logger );
				return new PasswordResetMailer( $logger );
			}
		);

		$container->set(
			RateLimiter::class,
			static fn (): RateLimiter => new RateLimiter()
		);

		$container->set(
			PasswordPolicy::class,
			static fn (): PasswordPolicy => new PasswordPolicy()
		);

		$container->set(
			RegistrationService::class,
			static function ( Container $c ): RegistrationService {
				$mailer = $c->get( VerificationMailer::class );
				assert( $mailer instanceof VerificationMailer );
				$policy = $c->get( PasswordPolicy::class );
				assert( $policy instanceof PasswordPolicy );
				return new RegistrationService( $mailer, $policy );
			}
		);

		$container->set(
			EmailVerificationService::class,
			static function ( Container $c ): EmailVerificationService {
				$mailer = $c->get( VerificationMailer::class );
				assert( $mailer instanceof VerificationMailer );
				$rate_limiter = $c->get( RateLimiter::class );
				assert( $rate_limiter instanceof RateLimiter );
				return new EmailVerificationService( $mailer, $rate_limiter );
			}
		);

		$container->set(
			PasswordResetService::class,
			static function ( Container $c ): PasswordResetService {
				$mailer = $c->get( PasswordResetMailer::class );
				assert( $mailer instanceof PasswordResetMailer );
				$rate_limiter = $c->get( RateLimiter::class );
				assert( $rate_limiter instanceof RateLimiter );
				$policy = $c->get( PasswordPolicy::class );
				assert( $policy instanceof PasswordPolicy );
				return new PasswordResetService( $mailer, $rate_limiter, $policy );
			}
		);

		$container->set(
			UnverifiedLoginBlocker::class,
			static fn (): UnverifiedLoginBlocker => new UnverifiedLoginBlocker()
		);

		$container->set(
			TokenIssuer::class,
			static fn (): TokenIssuer => new JwtBridgeTokenIssuer()
		);

		$container->set(
			AuthController::class,
			static function ( Container $c ): AuthController {
				$registration = $c->get( RegistrationService::class );
				assert( $registration instanceof RegistrationService );
				$verification = $c->get( EmailVerificationService::class );
				assert( $verification instanceof EmailVerificationService );
				$token_issuer = $c->get( TokenIssuer::class );
				assert( $token_issuer instanceof TokenIssuer );
				$password_reset = $c->get( PasswordResetService::class );
				assert( $password_reset instanceof PasswordResetService );
				return new AuthController(
					VL_LMS_API_NAMESPACE,
					$registration,
					$verification,
					$token_issuer,
					$password_reset
				);
			}
		);

		return $container;
	}
}
