<?php

declare(strict_types=1);

namespace VL\LMS;

use VL\LMS\Access\InstructorAccessFilter;
use VL\LMS\Access\TableBackedCoInstructorLookup;
use VL\LMS\Api\AuthController;
use VL\LMS\Api\EnrollmentRecordTransformer;
use VL\LMS\Api\EnrollmentsController;
use VL\LMS\Api\RestController;
use VL\LMS\Auth\JwtBridgeTokenIssuer;
use VL\LMS\Auth\JwtRestAuthenticator;
use VL\LMS\Auth\LoginGate\UnverifiedLoginBlocker;
use VL\LMS\Auth\Mail\PasswordResetMailer;
use VL\LMS\Auth\Mail\VerificationMailer;
use VL\LMS\Auth\PasswordPolicy;
use VL\LMS\Auth\PasswordReset\PasswordResetService;
use VL\LMS\Auth\Registration\RegistrationService;
use VL\LMS\Auth\RestAuthenticator;
use VL\LMS\Auth\TokenIssuer;
use VL\LMS\Auth\Verification\EmailVerificationService;
use VL\LMS\Catalog\CatalogController;
use VL\LMS\Catalog\CatalogDetailController;
use VL\LMS\Catalog\CatalogQuery;
use VL\LMS\Catalog\Search\RelevanceRanker;
use VL\LMS\Catalog\Search\SearchController;
use VL\LMS\Catalog\Search\SearchQuery;
use VL\LMS\Catalog\Search\SearchQueryRunner;
use VL\LMS\Catalog\Detail\CourseDetailTransformer;
use VL\LMS\Catalog\Detail\CurriculumTransformer;
use VL\LMS\Catalog\Detail\InstructorListTransformer;
use VL\LMS\Catalog\Detail\LessonSummaryTransformer;
use VL\LMS\Catalog\Detail\MaterialsTransformer;
use VL\LMS\Catalog\Detail\ModuleTransformer;
use VL\LMS\Catalog\Detail\PostFinder;
use VL\LMS\Catalog\Detail\RegistrationWindow;
use VL\LMS\Catalog\Detail\SeoBlockTransformer;
use VL\LMS\Catalog\Detail\WebinarDetailTransformer;
use VL\LMS\Catalog\TaxonomyController;
use VL\LMS\Catalog\TaxonomyTermTransformer;
use VL\LMS\Catalog\Transformers\CourseCardTransformer;
use VL\LMS\Catalog\Transformers\CoverImageTransformer;
use VL\LMS\Catalog\Transformers\LeadInstructorTransformer;
use VL\LMS\Catalog\Transformers\WebinarCardTransformer;
use VL\LMS\CPT\CptRegistrar;
use VL\LMS\Learn\Access\LessonAccessGate;
use VL\LMS\Learn\Content\BlockParser;
use VL\LMS\Learn\Content\BlockTransformerRegistry;
use VL\LMS\Learn\Content\Blocks\CodeBlockTransformer;
use VL\LMS\Learn\Content\Blocks\EmbedBlockTransformer;
use VL\LMS\Learn\Content\Blocks\FileBlockTransformer;
use VL\LMS\Learn\Content\Blocks\HeadingBlockTransformer;
use VL\LMS\Learn\Content\Blocks\HtmlFallbackBlockTransformer;
use VL\LMS\Learn\Content\Blocks\ImageBlockTransformer;
use VL\LMS\Learn\Content\Blocks\ListBlockTransformer;
use VL\LMS\Learn\Content\Blocks\ParagraphBlockTransformer;
use VL\LMS\Learn\Content\Blocks\QuoteBlockTransformer;
use VL\LMS\Learn\Content\Blocks\SeparatorBlockTransformer;
use VL\LMS\Learn\Content\Blocks\TableBlockTransformer;
use VL\LMS\Learn\EntityHierarchy;
use VL\LMS\Learn\LessonContentController;
use VL\LMS\Learn\LessonContentTransformer;
use VL\LMS\Learn\TopicContentTransformer;
use VL\LMS\Learn\Video\VideoPayloadBuilder;
use VL\LMS\Repositories\CourseInstructorRepository;
use VL\LMS\Repositories\EnrollmentRepository;
use VL\LMS\Repositories\LessonViewRepository;
use VL\LMS\Repositories\ProgressRepository;
use VL\LMS\Services\CourseInstructors\AuthorSyncService;
use VL\LMS\Services\Enrollment\EnrollmentService;
use VL\LMS\Support\HeroImageSize;
use VL\LMS\Support\Logger;
use VL\LMS\Taxonomy\DifficultyTermsInstaller;
use VL\LMS\Taxonomy\TaxonomyRegistrar;
use VL\LMS\User\InstructorProfileMetaRegistrar;
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

		$hero_image_size = $this->container->get( HeroImageSize::class );
		if ( $hero_image_size instanceof HeroImageSize ) {
			add_action( 'after_setup_theme', [ $hero_image_size, 'register' ] );
		}

		$cpt_registrar = new CptRegistrar();
		$cpt_registrar->register_hooks();

		$taxonomy_registrar = new TaxonomyRegistrar();
		$taxonomy_registrar->register_hooks();

		$instructor_profile_meta = $this->container->get( InstructorProfileMetaRegistrar::class );
		if ( $instructor_profile_meta instanceof InstructorProfileMetaRegistrar ) {
			add_action( 'init', [ $instructor_profile_meta, 'register' ] );
		}

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
		$catalog_controller = $this->container->get( CatalogController::class );
		if ( $catalog_controller instanceof CatalogController ) {
			$catalog_controller->register_routes();
		}
		$catalog_detail_controller = $this->container->get( CatalogDetailController::class );
		if ( $catalog_detail_controller instanceof CatalogDetailController ) {
			$catalog_detail_controller->register_routes();
		}
		$taxonomy_controller = $this->container->get( TaxonomyController::class );
		if ( $taxonomy_controller instanceof TaxonomyController ) {
			$taxonomy_controller->register_routes();
		}
		$search_controller = $this->container->get( SearchController::class );
		if ( $search_controller instanceof SearchController ) {
			$search_controller->register_routes();
		}
		$enrollments_controller = $this->container->get( EnrollmentsController::class );
		if ( $enrollments_controller instanceof EnrollmentsController ) {
			$enrollments_controller->register_routes();
		}
		$learn_controller = $this->container->get( LessonContentController::class );
		if ( $learn_controller instanceof LessonContentController ) {
			$learn_controller->register_routes();
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

		$container->set(
			InstructorProfileMetaRegistrar::class,
			static fn (): InstructorProfileMetaRegistrar => new InstructorProfileMetaRegistrar()
		);

		$container->set(
			HeroImageSize::class,
			static fn (): HeroImageSize => new HeroImageSize()
		);

		$container->set(
			CourseInstructorRepository::class,
			static fn (): CourseInstructorRepository => new CourseInstructorRepository()
		);

		$container->set(
			CoverImageTransformer::class,
			static fn (): CoverImageTransformer => new CoverImageTransformer()
		);

		$container->set(
			LeadInstructorTransformer::class,
			static fn (): LeadInstructorTransformer => new LeadInstructorTransformer()
		);

		$container->set(
			TaxonomyTermTransformer::class,
			static fn (): TaxonomyTermTransformer => new TaxonomyTermTransformer()
		);

		$container->set(
			CourseCardTransformer::class,
			static function ( Container $c ): CourseCardTransformer {
				$cover = $c->get( CoverImageTransformer::class );
				assert( $cover instanceof CoverImageTransformer );
				$lead = $c->get( LeadInstructorTransformer::class );
				assert( $lead instanceof LeadInstructorTransformer );
				$term = $c->get( TaxonomyTermTransformer::class );
				assert( $term instanceof TaxonomyTermTransformer );
				return new CourseCardTransformer( $cover, $lead, $term );
			}
		);

		$container->set(
			RegistrationWindow::class,
			static fn (): RegistrationWindow => new RegistrationWindow()
		);

		$container->set(
			WebinarCardTransformer::class,
			static function ( Container $c ): WebinarCardTransformer {
				$cover = $c->get( CoverImageTransformer::class );
				assert( $cover instanceof CoverImageTransformer );
				$lead = $c->get( LeadInstructorTransformer::class );
				assert( $lead instanceof LeadInstructorTransformer );
				$term = $c->get( TaxonomyTermTransformer::class );
				assert( $term instanceof TaxonomyTermTransformer );
				$reg_window = $c->get( RegistrationWindow::class );
				assert( $reg_window instanceof RegistrationWindow );
				return new WebinarCardTransformer( $cover, $lead, $term, $reg_window );
			}
		);

		$container->set(
			CatalogQuery::class,
			static fn (): CatalogQuery => new CatalogQuery()
		);

		$container->set(
			CatalogController::class,
			static function ( Container $c ): CatalogController {
				$query = $c->get( CatalogQuery::class );
				assert( $query instanceof CatalogQuery );
				$course_card = $c->get( CourseCardTransformer::class );
				assert( $course_card instanceof CourseCardTransformer );
				$webinar_card = $c->get( WebinarCardTransformer::class );
				assert( $webinar_card instanceof WebinarCardTransformer );
				$instructors = $c->get( CourseInstructorRepository::class );
				assert( $instructors instanceof CourseInstructorRepository );
				return new CatalogController(
					VL_LMS_API_NAMESPACE,
					$query,
					$course_card,
					$webinar_card,
					$instructors
				);
			}
		);

		$container->set(
			TaxonomyController::class,
			static function ( Container $c ): TaxonomyController {
				$term = $c->get( TaxonomyTermTransformer::class );
				assert( $term instanceof TaxonomyTermTransformer );
				return new TaxonomyController( VL_LMS_API_NAMESPACE, $term );
			}
		);

		$container->set(
			LessonSummaryTransformer::class,
			static fn (): LessonSummaryTransformer => new LessonSummaryTransformer()
		);

		$container->set(
			ModuleTransformer::class,
			static function ( Container $c ): ModuleTransformer {
				$lesson = $c->get( LessonSummaryTransformer::class );
				assert( $lesson instanceof LessonSummaryTransformer );
				return new ModuleTransformer( $lesson );
			}
		);

		$container->set(
			PostFinder::class,
			static fn (): PostFinder => new PostFinder()
		);

		$container->set(
			CurriculumTransformer::class,
			static function ( Container $c ): CurriculumTransformer {
				$module = $c->get( ModuleTransformer::class );
				assert( $module instanceof ModuleTransformer );
				$lesson = $c->get( LessonSummaryTransformer::class );
				assert( $lesson instanceof LessonSummaryTransformer );
				$finder = $c->get( PostFinder::class );
				assert( $finder instanceof PostFinder );
				return new CurriculumTransformer( $module, $lesson, $finder );
			}
		);

		$container->set(
			MaterialsTransformer::class,
			static fn (): MaterialsTransformer => new MaterialsTransformer()
		);

		$container->set(
			InstructorListTransformer::class,
			static fn (): InstructorListTransformer => new InstructorListTransformer()
		);

		$container->set(
			SeoBlockTransformer::class,
			static fn (): SeoBlockTransformer => new SeoBlockTransformer()
		);

		$container->set(
			CourseDetailTransformer::class,
			static function ( Container $c ): CourseDetailTransformer {
				$cover = $c->get( CoverImageTransformer::class );
				assert( $cover instanceof CoverImageTransformer );
				$term = $c->get( TaxonomyTermTransformer::class );
				assert( $term instanceof TaxonomyTermTransformer );
				$instructor_list = $c->get( InstructorListTransformer::class );
				assert( $instructor_list instanceof InstructorListTransformer );
				$curriculum = $c->get( CurriculumTransformer::class );
				assert( $curriculum instanceof CurriculumTransformer );
				$seo = $c->get( SeoBlockTransformer::class );
				assert( $seo instanceof SeoBlockTransformer );
				$instructors = $c->get( CourseInstructorRepository::class );
				assert( $instructors instanceof CourseInstructorRepository );
				return new CourseDetailTransformer( $cover, $term, $instructor_list, $curriculum, $seo, $instructors );
			}
		);

		$container->set(
			WebinarDetailTransformer::class,
			static function ( Container $c ): WebinarDetailTransformer {
				$cover = $c->get( CoverImageTransformer::class );
				assert( $cover instanceof CoverImageTransformer );
				$term = $c->get( TaxonomyTermTransformer::class );
				assert( $term instanceof TaxonomyTermTransformer );
				$instructor_list = $c->get( InstructorListTransformer::class );
				assert( $instructor_list instanceof InstructorListTransformer );
				$materials = $c->get( MaterialsTransformer::class );
				assert( $materials instanceof MaterialsTransformer );
				$reg_window = $c->get( RegistrationWindow::class );
				assert( $reg_window instanceof RegistrationWindow );
				$seo = $c->get( SeoBlockTransformer::class );
				assert( $seo instanceof SeoBlockTransformer );
				$instructors = $c->get( CourseInstructorRepository::class );
				assert( $instructors instanceof CourseInstructorRepository );
				return new WebinarDetailTransformer( $cover, $term, $instructor_list, $materials, $reg_window, $seo, $instructors );
			}
		);

		$container->set(
			CatalogDetailController::class,
			static function ( Container $c ): CatalogDetailController {
				$course = $c->get( CourseDetailTransformer::class );
				assert( $course instanceof CourseDetailTransformer );
				$webinar = $c->get( WebinarDetailTransformer::class );
				assert( $webinar instanceof WebinarDetailTransformer );
				return new CatalogDetailController( VL_LMS_API_NAMESPACE, $course, $webinar );
			}
		);

		$container->set(
			SearchQuery::class,
			static fn (): SearchQuery => new SearchQuery()
		);

		$container->set(
			RelevanceRanker::class,
			static fn (): RelevanceRanker => new RelevanceRanker()
		);

		$container->set(
			SearchQueryRunner::class,
			static fn (): SearchQueryRunner => new SearchQueryRunner()
		);

		$container->set(
			EnrollmentRepository::class,
			static fn (): EnrollmentRepository => new EnrollmentRepository()
		);

		$container->set(
			EnrollmentService::class,
			static function ( Container $c ): EnrollmentService {
				$repository = $c->get( EnrollmentRepository::class );
				assert( $repository instanceof EnrollmentRepository );
				return new EnrollmentService( $repository );
			}
		);

		$container->set(
			EnrollmentRecordTransformer::class,
			static function ( Container $c ): EnrollmentRecordTransformer {
				$cover = $c->get( CoverImageTransformer::class );
				assert( $cover instanceof CoverImageTransformer );
				return new EnrollmentRecordTransformer( $cover );
			}
		);

		$container->set(
			RestAuthenticator::class,
			static fn (): RestAuthenticator => new JwtRestAuthenticator()
		);

		$container->set(
			EnrollmentsController::class,
			static function ( Container $c ): EnrollmentsController {
				$authenticator = $c->get( RestAuthenticator::class );
				assert( $authenticator instanceof RestAuthenticator );
				$service = $c->get( EnrollmentService::class );
				assert( $service instanceof EnrollmentService );
				$repository = $c->get( EnrollmentRepository::class );
				assert( $repository instanceof EnrollmentRepository );
				$transformer = $c->get( EnrollmentRecordTransformer::class );
				assert( $transformer instanceof EnrollmentRecordTransformer );
				return new EnrollmentsController(
					VL_LMS_API_NAMESPACE,
					$authenticator,
					$service,
					$repository,
					$transformer
				);
			}
		);

		$container->set(
			SearchController::class,
			static function ( Container $c ): SearchController {
				$query = $c->get( SearchQuery::class );
				assert( $query instanceof SearchQuery );
				$runner = $c->get( SearchQueryRunner::class );
				assert( $runner instanceof SearchQueryRunner );
				$ranker = $c->get( RelevanceRanker::class );
				assert( $ranker instanceof RelevanceRanker );
				$course_card = $c->get( CourseCardTransformer::class );
				assert( $course_card instanceof CourseCardTransformer );
				$webinar_card = $c->get( WebinarCardTransformer::class );
				assert( $webinar_card instanceof WebinarCardTransformer );
				$instructors = $c->get( CourseInstructorRepository::class );
				assert( $instructors instanceof CourseInstructorRepository );
				return new SearchController(
					VL_LMS_API_NAMESPACE,
					$query,
					$runner,
					$ranker,
					$course_card,
					$webinar_card,
					$instructors
				);
			}
		);

		// ---------------------------------------------------------------
		// Learn subsystem (Phase 5.1)
		//
		// Order matters: foundations first (parser, video builder, block
		// transformers), then registry, then services that compose them.
		// HtmlFallbackBlockTransformer is appended LAST in the registry's
		// transformer list — that's the catch-all contract.
		// ---------------------------------------------------------------

		$container->set(
			EntityHierarchy::class,
			static fn (): EntityHierarchy => new EntityHierarchy()
		);

		$container->set(
			BlockParser::class,
			static fn (): BlockParser => new BlockParser()
		);

		$container->set(
			VideoPayloadBuilder::class,
			static fn (): VideoPayloadBuilder => new VideoPayloadBuilder()
		);

		$container->set(
			ProgressRepository::class,
			static fn (): ProgressRepository => new ProgressRepository()
		);

		// Registered now even though no caller in 5.1b consumes it; the
		// progress writer in 5.3 will pick it up.
		$container->set(
			LessonViewRepository::class,
			static fn (): LessonViewRepository => new LessonViewRepository()
		);

		$container->set(
			ParagraphBlockTransformer::class,
			static fn (): ParagraphBlockTransformer => new ParagraphBlockTransformer()
		);
		$container->set(
			HeadingBlockTransformer::class,
			static fn (): HeadingBlockTransformer => new HeadingBlockTransformer()
		);
		$container->set(
			ListBlockTransformer::class,
			static fn (): ListBlockTransformer => new ListBlockTransformer()
		);
		$container->set(
			ImageBlockTransformer::class,
			static fn (): ImageBlockTransformer => new ImageBlockTransformer()
		);
		$container->set(
			QuoteBlockTransformer::class,
			static fn (): QuoteBlockTransformer => new QuoteBlockTransformer()
		);
		$container->set(
			EmbedBlockTransformer::class,
			static function ( Container $c ): EmbedBlockTransformer {
				$video_builder = $c->get( VideoPayloadBuilder::class );
				assert( $video_builder instanceof VideoPayloadBuilder );
				return new EmbedBlockTransformer( $video_builder );
			}
		);
		$container->set(
			SeparatorBlockTransformer::class,
			static fn (): SeparatorBlockTransformer => new SeparatorBlockTransformer()
		);
		$container->set(
			CodeBlockTransformer::class,
			static fn (): CodeBlockTransformer => new CodeBlockTransformer()
		);
		$container->set(
			TableBlockTransformer::class,
			static fn (): TableBlockTransformer => new TableBlockTransformer()
		);
		$container->set(
			FileBlockTransformer::class,
			static fn (): FileBlockTransformer => new FileBlockTransformer()
		);
		$container->set(
			HtmlFallbackBlockTransformer::class,
			static fn (): HtmlFallbackBlockTransformer => new HtmlFallbackBlockTransformer()
		);

		$container->set(
			BlockTransformerRegistry::class,
			static function ( Container $c ): BlockTransformerRegistry {
				$paragraph = $c->get( ParagraphBlockTransformer::class );
				assert( $paragraph instanceof ParagraphBlockTransformer );
				$heading = $c->get( HeadingBlockTransformer::class );
				assert( $heading instanceof HeadingBlockTransformer );
				$list = $c->get( ListBlockTransformer::class );
				assert( $list instanceof ListBlockTransformer );
				$image = $c->get( ImageBlockTransformer::class );
				assert( $image instanceof ImageBlockTransformer );
				$quote = $c->get( QuoteBlockTransformer::class );
				assert( $quote instanceof QuoteBlockTransformer );
				$embed = $c->get( EmbedBlockTransformer::class );
				assert( $embed instanceof EmbedBlockTransformer );
				$separator = $c->get( SeparatorBlockTransformer::class );
				assert( $separator instanceof SeparatorBlockTransformer );
				$code = $c->get( CodeBlockTransformer::class );
				assert( $code instanceof CodeBlockTransformer );
				$table = $c->get( TableBlockTransformer::class );
				assert( $table instanceof TableBlockTransformer );
				$file = $c->get( FileBlockTransformer::class );
				assert( $file instanceof FileBlockTransformer );
				$fallback = $c->get( HtmlFallbackBlockTransformer::class );
				assert( $fallback instanceof HtmlFallbackBlockTransformer );

				return new BlockTransformerRegistry(
					[
						$paragraph,
						$heading,
						$list,
						$image,
						$quote,
						$embed,
						$separator,
						$code,
						$table,
						$file,
						// HtmlFallback MUST stay last — it claims every block name.
						$fallback,
					]
				);
			}
		);

		$container->set(
			LessonAccessGate::class,
			static function ( Container $c ): LessonAccessGate {
				$service = $c->get( EnrollmentService::class );
				assert( $service instanceof EnrollmentService );
				$progress = $c->get( ProgressRepository::class );
				assert( $progress instanceof ProgressRepository );
				$hierarchy = $c->get( EntityHierarchy::class );
				assert( $hierarchy instanceof EntityHierarchy );
				return new LessonAccessGate( $service, $progress, $hierarchy );
			}
		);

		$container->set(
			LessonContentTransformer::class,
			static function ( Container $c ): LessonContentTransformer {
				$parser = $c->get( BlockParser::class );
				assert( $parser instanceof BlockParser );
				$registry = $c->get( BlockTransformerRegistry::class );
				assert( $registry instanceof BlockTransformerRegistry );
				$video_builder = $c->get( VideoPayloadBuilder::class );
				assert( $video_builder instanceof VideoPayloadBuilder );
				$hierarchy = $c->get( EntityHierarchy::class );
				assert( $hierarchy instanceof EntityHierarchy );
				$progress = $c->get( ProgressRepository::class );
				assert( $progress instanceof ProgressRepository );
				return new LessonContentTransformer( $parser, $registry, $video_builder, $hierarchy, $progress );
			}
		);

		$container->set(
			TopicContentTransformer::class,
			static function ( Container $c ): TopicContentTransformer {
				$parser = $c->get( BlockParser::class );
				assert( $parser instanceof BlockParser );
				$registry = $c->get( BlockTransformerRegistry::class );
				assert( $registry instanceof BlockTransformerRegistry );
				$video_builder = $c->get( VideoPayloadBuilder::class );
				assert( $video_builder instanceof VideoPayloadBuilder );
				$hierarchy = $c->get( EntityHierarchy::class );
				assert( $hierarchy instanceof EntityHierarchy );
				$progress = $c->get( ProgressRepository::class );
				assert( $progress instanceof ProgressRepository );
				return new TopicContentTransformer( $parser, $registry, $video_builder, $hierarchy, $progress );
			}
		);

		$container->set(
			LessonContentController::class,
			static function ( Container $c ): LessonContentController {
				$authenticator = $c->get( RestAuthenticator::class );
				assert( $authenticator instanceof RestAuthenticator );
				$gate = $c->get( LessonAccessGate::class );
				assert( $gate instanceof LessonAccessGate );
				$lesson_transformer = $c->get( LessonContentTransformer::class );
				assert( $lesson_transformer instanceof LessonContentTransformer );
				$topic_transformer = $c->get( TopicContentTransformer::class );
				assert( $topic_transformer instanceof TopicContentTransformer );
				return new LessonContentController(
					VL_LMS_API_NAMESPACE,
					$authenticator,
					$gate,
					$lesson_transformer,
					$topic_transformer
				);
			}
		);

		return $container;
	}
}
