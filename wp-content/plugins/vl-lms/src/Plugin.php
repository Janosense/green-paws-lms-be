<?php

declare(strict_types=1);

namespace VL\LMS;

use VL\LMS\Access\InstructorAccessFilter;
use VL\LMS\Access\TableBackedCoInstructorLookup;
use VL\LMS\Api\RestController;
use VL\LMS\CPT\CptRegistrar;
use VL\LMS\Repositories\CourseInstructorRepository;
use VL\LMS\Services\CourseInstructors\AuthorSyncService;
use VL\LMS\Support\Logger;
use VL\LMS\Taxonomy\TaxonomyRegistrar;

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

		return $container;
	}
}
