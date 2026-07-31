<?php

declare(strict_types=1);

namespace VL\LMS;

use VL\LMS\Access\InstructorAccessFilter;
use VL\LMS\Access\TableBackedCoInstructorLookup;
use VL\LMS\Admin\AdminProvider;
use VL\LMS\Admin\Analytics\AnalyticsCron;
use VL\LMS\Admin\Analytics\AnalyticsPage;
use VL\LMS\Admin\Analytics\AnalyticsRollupService;
use VL\LMS\Admin\Api\AdminPreviewController;
use VL\LMS\Admin\Assignments\GradingQueuePage;
use VL\LMS\Admin\Assignments\SubmissionDetailPage;
use VL\LMS\Admin\Columns\CurriculumListColumns;
use VL\LMS\Admin\Dashboard\CourseStatsQuery;
use VL\LMS\Admin\Dashboard\InstructorDashboardPage;
use VL\LMS\Admin\Groups\GroupDetailPage;
use VL\LMS\Admin\Groups\GroupFormHandler;
use VL\LMS\Admin\Groups\GroupsListPage;
use VL\LMS\Admin\Menu\AdminMenuProvider;
use VL\LMS\Admin\Students\StudentDetailPage;
use VL\LMS\Admin\Students\StudentEnrollmentFormHandler;
use VL\LMS\Admin\Students\StudentsListPage;
use VL\LMS\Admin\MetaBoxes\AssignmentMetaBox;
use VL\LMS\Admin\Lessons\LessonPickerAjaxHandler;
use VL\LMS\Admin\Topics\TopicPickerAjaxHandler;
use VL\LMS\Admin\Questions\QuestionPickerAjaxHandler;
use VL\LMS\Admin\Quizzes\QuizPickerAjaxHandler;
use VL\LMS\Admin\Assignments\AssignmentPickerAjaxHandler;
use VL\LMS\Admin\MetaBoxes\ChildList\CourseLessonListMetaBox;
use VL\LMS\Admin\MetaBoxes\ChildList\LessonListMetaBox;
use VL\LMS\Admin\MetaBoxes\ChildList\ModuleListMetaBox;
use VL\LMS\Admin\MetaBoxes\ChildList\SessionListMetaBox;
use VL\LMS\Admin\MetaBoxes\ChildList\QuestionListMetaBox;
use VL\LMS\Admin\MetaBoxes\ChildList\QuizListMetaBox;
use VL\LMS\Admin\MetaBoxes\ChildList\AssignmentListMetaBox;
use VL\LMS\Admin\MetaBoxes\ChildList\TopicListMetaBox;
use VL\LMS\Admin\MetaBoxes\AuthorMetaBox;
use VL\LMS\Admin\MetaBoxes\CourseInstructorsMetaBox;
use VL\LMS\Admin\MetaBoxes\CourseMetaBox;
use VL\LMS\Admin\MetaBoxes\LessonMetaBox;
use VL\LMS\Admin\MetaBoxes\ModuleMetaBox;
use VL\LMS\Admin\MetaBoxes\QuizMetaBox;
use VL\LMS\Admin\MetaBoxes\QuizQuestionMetaBox;
use VL\LMS\Admin\MetaBoxes\SessionMetaBox;
use VL\LMS\Admin\MetaBoxes\TopicMetaBox;
use VL\LMS\Admin\MetaBoxes\WebinarMetaBox;
use VL\LMS\Admin\Orders\OrderDetailPage;
use VL\LMS\Admin\Orders\OrdersListPage;
use VL\LMS\Admin\Reorder\ReorderAjaxHandler;
use VL\LMS\Admin\Settings\LiqPaySettingsSection;
use VL\LMS\Admin\Settings\SettingsPage;
use VL\LMS\Admin\Settings\ZoomSettingsSection;
use VL\LMS\Api\AdminAssignmentsController;
use VL\LMS\Api\AdminOrdersController;
use VL\LMS\Api\AssignmentsController;
use VL\LMS\Api\AuthController;
use VL\LMS\Api\CertificatesController;
use VL\LMS\Api\CertificateVerificationController;
use VL\LMS\Api\EnrollmentRecordTransformer;
use VL\LMS\Api\EnrollmentsController;
use VL\LMS\Api\ProgressController;
use VL\LMS\Api\QuizAttemptsController;
use VL\LMS\Api\RestController;
use VL\LMS\Api\SessionAccessController;
use VL\LMS\Api\Transformers\QuizAttemptHistoryTransformer;
use VL\LMS\Api\Transformers\QuizAttemptStateTransformer;
use VL\LMS\Api\Transformers\SubmissionTransformer;
use VL\LMS\Api\Transformers\WebinarRegistrationTransformer;
use VL\LMS\Api\WebinarAccessController;
use VL\LMS\Api\OrdersController;
use VL\LMS\Api\OrderTransformer;
use VL\LMS\Api\PaymentsController;
use VL\LMS\Api\PreparedPaymentTransformer;
use VL\LMS\Api\WebinarRegistrationsController;
use VL\LMS\Api\ZoomWebhookController;
use VL\LMS\Orders\OrderEnrollmentFanout;
use VL\LMS\Orders\OrderExpirationCron;
use VL\LMS\Orders\OrderService;
use VL\LMS\Orders\PriceResolver;
use VL\LMS\Orders\PurchasableLookup;
use VL\LMS\Refunds\OrderRefundEnrollmentRevoker;
use VL\LMS\Refunds\RefundService;
use VL\LMS\Payments\LiqPay\CallbackHandler as LiqPayCallbackHandler;
use VL\LMS\Payments\LiqPay\CallbackParser as LiqPayCallbackParser;
use VL\LMS\Payments\LiqPay\HttpClient as LiqPayHttpClient;
use VL\LMS\Payments\LiqPay\LiqPayClient;
use VL\LMS\Payments\LiqPay\PayloadBuilder as LiqPayPayloadBuilder;
use VL\LMS\Payments\LiqPay\RefundResponseParser as LiqPayRefundResponseParser;
use VL\LMS\Payments\LiqPay\Settings as LiqPaySettings;
use VL\LMS\Payments\LiqPay\SignatureBuilder as LiqPaySignatureBuilder;
use VL\LMS\Payments\LiqPay\SignatureVerifier as LiqPaySignatureVerifier;
use VL\LMS\Payments\PaymentProvider;
use VL\LMS\Payments\RefundCapableProvider;
use VL\LMS\Repositories\AssignmentSubmissionRepository;
use VL\LMS\Repositories\OrderRepository;
use VL\LMS\Repositories\PaymentRepository;
use VL\LMS\Certificate\CertificateAutoIssuer;
use VL\LMS\Certificate\CertificateRevoker;
use VL\LMS\Certificate\CertificateService;
use VL\LMS\Certificate\Pdf\CertificateRenderer;
use VL\LMS\Certificate\Pdf\PdfGenerator;
use VL\LMS\Certificate\Pdf\QrCodeGenerator;
use VL\LMS\Certificate\SnapshotBuilder;
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
use VL\LMS\Cli\DemoCommand;
use VL\LMS\Cli\QuizCommand;
use VL\LMS\Services\CourseInstructors\CourseInstructorService;
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
use VL\LMS\Database\SchemaManager;
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
use VL\LMS\Learn\CurriculumController;
use VL\LMS\Learn\CurriculumTransformer as LearnCurriculumTransformer;
use VL\LMS\Learn\EntityHierarchy;
use VL\LMS\Learn\LessonContentController;
use VL\LMS\Learn\LessonContentTransformer;
use VL\LMS\Learn\Access\SessionAccessGate;
use VL\LMS\Learn\LessonNodeTransformer;
use VL\LMS\Learn\ModuleNodeTransformer;
use VL\LMS\Learn\SessionContentTransformer;
use VL\LMS\Learn\SessionLookup;
use VL\LMS\Learn\SessionNodeTransformer;
use VL\LMS\Learn\NextEntityResolver;
use VL\LMS\Learn\Progression\CurriculumOrder;
use VL\LMS\Learn\Progression\ProgressionGate;
use VL\LMS\Learn\QuizNodeTransformer;
use VL\LMS\Learn\TopicContentTransformer;
use VL\LMS\Learn\TopicNodeTransformer;
use VL\LMS\Learn\Video\VideoPayloadBuilder;
use VL\LMS\Repositories\CertificateRepository;
use VL\LMS\Repositories\CourseInstructorRepository;
use VL\LMS\Quiz\Access\QuizAccessGate;
use VL\LMS\Quiz\QuestionDeliveryTransformer;
use VL\LMS\Quiz\QuizAttemptService;
use VL\LMS\Quiz\QuizCourseResolver;
use VL\LMS\Quiz\Scoring\MultipleChoiceScorer;
use VL\LMS\Quiz\Scoring\QuizScoringEngine;
use VL\LMS\Quiz\Scoring\SingleChoiceScorer;
use VL\LMS\Quiz\Scoring\TextScorer;
use VL\LMS\Quiz\Scoring\TrueFalseScorer;
use VL\LMS\Repositories\EnrollmentRepository;
use VL\LMS\Repositories\GroupAccessRepository;
use VL\LMS\Repositories\GroupMemberRepository;
use VL\LMS\Repositories\GroupRepository;
use VL\LMS\Repositories\LessonViewRepository;
use VL\LMS\Repositories\ProgressRepository;
use VL\LMS\Repositories\QuizAnswerRepository;
use VL\LMS\Repositories\QuizAttemptRepository;
use VL\LMS\Repositories\SessionAttendanceRepository;
use VL\LMS\Repositories\WebinarRegistrationRepository;
use VL\LMS\Repositories\ZoomWebhookEventRepository;
use VL\LMS\Services\Assignments\AssignmentCompletionListener;
use VL\LMS\Services\Assignments\AssignmentSubmissionService;
use VL\LMS\Services\CourseInstructors\AuthorSyncService;
use VL\LMS\Services\Enrollment\EnrollmentService;
use VL\LMS\Services\Enrollment\EnrollmentStatsService;
use VL\LMS\Services\Groups\GroupEnrollmentService;
use VL\LMS\Services\Groups\GroupService;
use VL\LMS\Services\JoinWindowPolicy;
use VL\LMS\Services\Progress\CompletionPropagator;
use VL\LMS\Services\Webinars\WebinarAccessGate;
use VL\LMS\Services\Webinars\WebinarLookup;
use VL\LMS\Services\Webinars\WebinarRegistrationService;
use VL\LMS\Services\Zoom\HttpZoomClient;
use VL\LMS\Services\Zoom\PostLookup;
use VL\LMS\Services\Zoom\Settings\ZoomSettingsProvider;
use VL\LMS\Services\Zoom\Sync\DiffDetector;
use VL\LMS\Services\Zoom\Sync\MeetingPayloadBuilder;
use VL\LMS\Services\Zoom\Sync\MeetingSynchronizer;
use VL\LMS\Services\Zoom\Sync\MeetingSynchronizerBootstrap;
use VL\LMS\Services\Zoom\Sync\PasswordGenerator;
use VL\LMS\Services\Zoom\Sync\PostMetaAccessor;
use VL\LMS\Services\Zoom\Sync\SyncLock;
use VL\LMS\Services\Zoom\TokenHttpClient;
use VL\LMS\Services\Zoom\TokenProvider;
use VL\LMS\Services\Zoom\Webhook\HandlerRegistry;
use VL\LMS\Services\Zoom\Webhook\Handlers\MeetingEndedHandler;
use VL\LMS\Services\Zoom\Webhook\Handlers\MeetingStartedHandler;
use VL\LMS\Services\Zoom\Webhook\Handlers\ParticipantJoinedHandler;
use VL\LMS\Services\Zoom\Webhook\Handlers\ParticipantLeftHandler;
use VL\LMS\Services\Zoom\Webhook\Handlers\RecordingCompletedHandler;
use VL\LMS\Services\Zoom\Webhook\UrlValidationResponder;
use VL\LMS\Services\Zoom\Webhook\WebhookEventDispatcher;
use VL\LMS\Services\Zoom\Webhook\WebhookRequestParser;
use VL\LMS\Services\Zoom\Webhook\WebhookSignatureVerifier;
use VL\LMS\Services\Zoom\Webhook\WebinarJoinTracker;
use VL\LMS\Services\Zoom\WpHttpTokenHttpClient;
use VL\LMS\Services\Zoom\WpRemoteZoomApiHttpClient;
use VL\LMS\Services\Zoom\ZoomApiHttpClient;
use VL\LMS\Services\Zoom\ZoomClient;
use VL\LMS\Services\Progress\CourseProgressCalculator;
use VL\LMS\Services\Progress\PositionWriteRule;
use VL\LMS\Services\Progress\ProgressResetService;
use VL\LMS\Services\Progress\ProgressService;
use VL\LMS\Services\Progress\SessionAttendanceProgressListener;
use VL\LMS\Mail\CertificateIssuedMailer;
use VL\LMS\Mail\HtmlMailSender;
use VL\LMS\Mail\CourseAccessGrantedMailer;
use VL\LMS\Mail\OrderFailedMailer;
use VL\LMS\Mail\OrderPaidMailer;
use VL\LMS\Mail\OrderRefundedMailer;
use VL\LMS\Mail\RecordingReadyMailer;
use VL\LMS\Mail\SessionReminderMailer;
use VL\LMS\Mail\WebinarReminderMailer;
use VL\LMS\Services\Notifications\CertificateIssuedListener;
use VL\LMS\Services\Notifications\OrderFailedListener;
use VL\LMS\Services\Notifications\OrderPaidListener;
use VL\LMS\Services\Notifications\OrderRefundedListener;
use VL\LMS\Services\Notifications\RecordingReadyListener;
use VL\LMS\Services\Notifications\ReminderDispatcher;
use VL\LMS\Services\Notifications\ReminderScheduler;
use VL\LMS\Slug\CyrillicTransliterator;
use VL\LMS\Slug\SlugTransliterationListener;
use VL\LMS\Support\AppUrlResolver;
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

		// Self-heal pending schema migrations. The activation hook only fires
		// on manual (de)activation, so a file-level deploy that bumps
		// CURRENT_DB_VERSION would otherwise keep serving new code against
		// the old schema until someone reactivates the plugin — with queries
		// referencing columns that don't exist yet. `init` fires on web,
		// REST, and cron requests alike, and install() short-circuits on a
		// version match against an autoloaded option, so the steady-state
		// cost is one string compare per request.
		add_action( 'init', [ SchemaManager::class, 'install' ] );

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

		// Auto-transliterate Cyrillic slugs. Inner-course CPTs (lessons, topics,
		// modules, quizzes, sessions, assignments, quiz questions) are rewritten
		// on every save — they're auth-gated and never indexed. Catalog CPTs
		// (`vl_course`, `vl_webinar`) are rewritten only at creation (no DB row
		// yet, or existing row in `auto-draft`) so the public detail endpoint
		// can route to them; once saved non-`auto-draft`, the slug is editor
		// territory and we never silently rewrite. See `SlugTransliterationListener`.
		$slug_transliteration_listener = new SlugTransliterationListener( new CyrillicTransliterator() );
		$slug_transliteration_listener->register_hooks();

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

		// CLI subsystem (Phase 5.7). Registered only inside WP-CLI so the
		// class file is never autoloaded for web requests.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'vl-lms demo', new DemoCommand( $this->container ) );
			\WP_CLI::add_command( 'vl-lms quiz', new QuizCommand( $this->container ) );
		}

		// Phase 6.3 — certificate option defaults. `add_option` is a
		// no-op when the option already exists, so this is safe on every
		// boot.
		add_option( 'vl_lms_certificate_issuer', 'Green Paws LMS' );
		add_option( 'vl_lms_frontend_url', '' );

		// Phase 6.3 — wire the certificate auto-issuer to course
		// completion and the revoker to enrollment revocation.
		$certificate_auto_issuer = $this->container->get( CertificateAutoIssuer::class );
		if ( $certificate_auto_issuer instanceof CertificateAutoIssuer ) {
			$certificate_auto_issuer->register();
		}
		$certificate_revoker = $this->container->get( CertificateRevoker::class );
		if ( $certificate_revoker instanceof CertificateRevoker ) {
			$certificate_revoker->register();
		}

		// Phase 7.4 — register the session-attendance → progress listener so
		// attendance webhooks recompute progress_pct and re-evaluate the
		// course-completion contract for the joining user.
		$session_attendance_listener = $this->container->get( SessionAttendanceProgressListener::class );
		if ( $session_attendance_listener instanceof SessionAttendanceProgressListener ) {
			$session_attendance_listener->register();
		}

		// Phase 7.1 — register the Zoom meeting synchronizer's WP hooks.
		// Wired unconditionally; if credentials are absent, each sync()
		// returns SKIPPED/NOT_CONFIGURED instead of producing partial state.
		$meeting_sync_bootstrap = $this->container->get( MeetingSynchronizerBootstrap::class );
		if ( $meeting_sync_bootstrap instanceof MeetingSynchronizerBootstrap ) {
			$meeting_sync_bootstrap->register();
		}

		// Phase 7.6 — wire the reminder scheduler to save_post_*, the cron
		// hook to the dispatcher, and the recording-ready / certificate-issued
		// listeners to their respective domain actions. The scheduler hooks
		// at priority 30 (after MeetingSynchronizer's 20) so a fresh meeting
		// id is in post-meta by the time we look at the row.
		$reminder_scheduler = $this->container->get( ReminderScheduler::class );
		if ( $reminder_scheduler instanceof ReminderScheduler ) {
			$reminder_scheduler->register();
		}
		$reminder_dispatcher = $this->container->get( ReminderDispatcher::class );
		if ( $reminder_dispatcher instanceof ReminderDispatcher ) {
			add_action(
				ReminderScheduler::CRON_HOOK,
				[ $reminder_dispatcher, 'dispatch' ],
				10,
				3
			);
		}
		$recording_listener = $this->container->get( RecordingReadyListener::class );
		if ( $recording_listener instanceof RecordingReadyListener ) {
			$recording_listener->register();
		}
		$cert_issued_listener = $this->container->get( CertificateIssuedListener::class );
		if ( $cert_issued_listener instanceof CertificateIssuedListener ) {
			$cert_issued_listener->register();
		}

		// Phase 8.2 — wire the order fan-out listener (priority 10 for
		// provisioning-first ordering; future mailers / observers attach
		// at priority ≥ 20). The listener idempotently calls
		// EnrollmentService::enroll or WebinarRegistrationService::register_for_purchase
		// based on the order's entity_type.
		$order_fanout = $this->container->get( OrderEnrollmentFanout::class );
		if ( $order_fanout instanceof OrderEnrollmentFanout ) {
			add_action( 'vl_lms_order_paid', [ $order_fanout, 'on_order_paid' ], 10, 2 );
		}

		// Phase 8.2 — register the hourly order-expiration cron and wire the
		// tick callback. `register()` is idempotent (guarded by
		// wp_next_scheduled), safe on every boot.
		$order_expiration_cron = $this->container->get( OrderExpirationCron::class );
		if ( $order_expiration_cron instanceof OrderExpirationCron ) {
			$order_expiration_cron->register();
			add_action( OrderExpirationCron::HOOK_NAME, [ $order_expiration_cron, 'on_tick' ] );
		}

		// Phase 9.3 — wire the nightly analytics rollup tick. Scheduling
		// itself happens in Activator::activate() (and Deactivator clears
		// the hook); this only attaches the tick callback so the cron has
		// somewhere to land.
		$analytics_cron = $this->container->get( AnalyticsCron::class );
		if ( $analytics_cron instanceof AnalyticsCron ) {
			add_action( AnalyticsCron::HOOK_NAME, [ $analytics_cron, 'handle' ] );
		}
		$analytics_page = $this->container->get( AnalyticsPage::class );
		if ( $analytics_page instanceof AnalyticsPage ) {
			add_action( 'admin_enqueue_scripts', [ $analytics_page, 'enqueue_assets' ] );
		}

		// Phase 9.4 — wire the assignment-completion listener and the
		// `admin-post.php` handlers behind the grading queue's grade /
		// reject buttons. The listener fires on `vl_lms_assignment_graded`,
		// which the service raises only on passing scores; the handlers
		// are nonce + cap gated inside their methods.
		$assignment_listener = $this->container->get( AssignmentCompletionListener::class );
		if ( $assignment_listener instanceof AssignmentCompletionListener ) {
			add_action( 'vl_lms_assignment_graded', [ $assignment_listener, 'handle' ], 10, 2 );
		}
		$submission_detail_page = $this->container->get( SubmissionDetailPage::class );
		if ( $submission_detail_page instanceof SubmissionDetailPage ) {
			add_action(
				'admin_post_' . SubmissionDetailPage::GRADE_ACTION,
				[ $submission_detail_page, 'handle_grade' ]
			);
			add_action(
				'admin_post_' . SubmissionDetailPage::REJECT_ACTION,
				[ $submission_detail_page, 'handle_reject' ]
			);
		}

		// Phase 9.5 — wp-admin Settings page form handler. Nonce + cap
		// gated inside the method; constants in wp-config.php still take
		// precedence so the loop silently skips fields whose constant is
		// defined.
		$settings_page = $this->container->get( SettingsPage::class );
		if ( $settings_page instanceof SettingsPage ) {
			add_action(
				'admin_post_' . SettingsPage::SAVE_ACTION,
				[ $settings_page, 'handle_save' ]
			);
		}

		// Phase 9.8 — Groups admin form handler. Registers six admin_post_*
		// callbacks (create/update/member-add/member-remove/course-grant/
		// course-revoke) plus two wp_ajax_* search endpoints. Each action
		// is capability + nonce gated inside the handler.
		$group_form_handler = $this->container->get( GroupFormHandler::class );
		if ( $group_form_handler instanceof GroupFormHandler ) {
			$group_form_handler->register();
		}

		// Per-student detail page grant/revoke handlers (admin_post_*),
		// gated on vl_manage_enrollments inside the handler.
		$student_enrollment_handler = $this->container->get( StudentEnrollmentFormHandler::class );
		if ( $student_enrollment_handler instanceof StudentEnrollmentFormHandler ) {
			$student_enrollment_handler->register();
		}

		// Phase 8.3 — wire the order-refund revocation listener. Subscribes
		// to vl_lms_order_refunded at priority 10. The certificate-revocation
		// chain (Phase 6.3) cascades automatically when EnrollmentService::revoke
		// fires vl_lms_enrollment_revoked.
		$refund_revoker = $this->container->get( OrderRefundEnrollmentRevoker::class );
		if ( $refund_revoker instanceof OrderRefundEnrollmentRevoker ) {
			add_action( 'vl_lms_order_refunded', [ $refund_revoker, 'on_order_refunded' ], 10, 2 );
		}

		// Phase 8.5 — transactional email listeners at priority 20 (after
		// provisioning / revocation side-effects at priority 10). Each
		// listener bridges its domain action to the matching mailer.
		$order_paid_listener = $this->container->get( OrderPaidListener::class );
		if ( $order_paid_listener instanceof OrderPaidListener ) {
			$order_paid_listener->register();
		}
		$order_refunded_listener = $this->container->get( OrderRefundedListener::class );
		if ( $order_refunded_listener instanceof OrderRefundedListener ) {
			$order_refunded_listener->register();
		}
		$order_failed_listener = $this->container->get( OrderFailedListener::class );
		if ( $order_failed_listener instanceof OrderFailedListener ) {
			$order_failed_listener->register();
		}

		// Phase 8.6 — admin "Замовлення" screen. Top-level menu, position 26
		// (just below "Comments" at 25). Cap-gated on `vl_refund_orders`
		// (administrator-only) so non-admins do not see the menu item; the
		// detail page enforces granular cap checks for the refund button.
		$orders_list_page  = $this->container->get( OrdersListPage::class );
		$order_detail_page = $this->container->get( OrderDetailPage::class );
		if ( $orders_list_page instanceof OrdersListPage && $order_detail_page instanceof OrderDetailPage ) {
			add_action(
				'admin_menu',
				static function () use ( $orders_list_page, $order_detail_page ): void {
					add_menu_page(
						'Замовлення',
						'Замовлення',
						'vl_refund_orders',
						'vl-lms-orders',
						[ $orders_list_page, 'render' ],
						'dashicons-cart',
						26
					);
					add_submenu_page(
						'',
						'Деталі замовлення',
						'Деталі замовлення',
						'vl_refund_orders',
						'vl-lms-order-detail',
						[ $order_detail_page, 'render' ]
					);
				}
			);
		}

		// Phase 9.0 — typed CPT meta-boxes + co-instructor UI. The provider
		// only registers wp-admin hooks (add_meta_boxes, save_post,
		// admin_enqueue_scripts, ajax) so guarding boot() under is_admin()
		// keeps the admin classes off REST and CLI request paths.
		$container = $this->container;
		add_action(
			'init',
			static function () use ( $container ): void {
				if ( ! is_admin() ) {
					return;
				}
				$admin_provider = $container->get( AdminProvider::class );
				if ( $admin_provider instanceof AdminProvider ) {
					$admin_provider->boot();
				}
				$curriculum_columns = $container->get( CurriculumListColumns::class );
				if ( $curriculum_columns instanceof CurriculumListColumns ) {
					$curriculum_columns->boot();
				}
			},
			15
		);

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
		$curriculum_controller = $this->container->get( CurriculumController::class );
		if ( $curriculum_controller instanceof CurriculumController ) {
			$curriculum_controller->register_routes();
		}
		$progress_controller = $this->container->get( ProgressController::class );
		if ( $progress_controller instanceof ProgressController ) {
			$progress_controller->register_routes();
		}
		$quiz_attempts_controller = $this->container->get( QuizAttemptsController::class );
		if ( $quiz_attempts_controller instanceof QuizAttemptsController ) {
			$quiz_attempts_controller->register_routes();
		}
		$certificates_controller = $this->container->get( CertificatesController::class );
		if ( $certificates_controller instanceof CertificatesController ) {
			$certificates_controller->register_routes();
		}
		$certificate_verification_controller = $this->container->get( CertificateVerificationController::class );
		if ( $certificate_verification_controller instanceof CertificateVerificationController ) {
			$certificate_verification_controller->register_routes();
		}
		$zoom_webhook_controller = $this->container->get( ZoomWebhookController::class );
		if ( $zoom_webhook_controller instanceof ZoomWebhookController ) {
			$zoom_webhook_controller->register_routes();
		}
		$webinar_registrations_controller = $this->container->get( WebinarRegistrationsController::class );
		if ( $webinar_registrations_controller instanceof WebinarRegistrationsController ) {
			$webinar_registrations_controller->register_routes();
		}
		$webinar_access_controller = $this->container->get( WebinarAccessController::class );
		if ( $webinar_access_controller instanceof WebinarAccessController ) {
			$webinar_access_controller->register_routes();
		}
		$session_access_controller = $this->container->get( SessionAccessController::class );
		if ( $session_access_controller instanceof SessionAccessController ) {
			$session_access_controller->register_routes();
		}
		$orders_controller = $this->container->get( OrdersController::class );
		if ( $orders_controller instanceof OrdersController ) {
			$orders_controller->register_routes();
		}
		$payments_controller = $this->container->get( PaymentsController::class );
		if ( $payments_controller instanceof PaymentsController ) {
			$payments_controller->register_routes();
		}
		$admin_orders_controller = $this->container->get( AdminOrdersController::class );
		if ( $admin_orders_controller instanceof AdminOrdersController ) {
			$admin_orders_controller->register_routes();
		}
		$admin_preview_controller = $this->container->get( AdminPreviewController::class );
		if ( $admin_preview_controller instanceof AdminPreviewController ) {
			$admin_preview_controller->register_routes();
		}
		$assignments_controller = $this->container->get( AssignmentsController::class );
		if ( $assignments_controller instanceof AssignmentsController ) {
			$assignments_controller->register_routes();
		}
		$admin_assignments_controller = $this->container->get( AdminAssignmentsController::class );
		if ( $admin_assignments_controller instanceof AdminAssignmentsController ) {
			$admin_assignments_controller->register_routes();
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
			CourseInstructorService::class,
			static function ( Container $c ): CourseInstructorService {
				$repo = $c->get( CourseInstructorRepository::class );
				assert( $repo instanceof CourseInstructorRepository );
				return new CourseInstructorService( $repo );
			}
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
			GroupRepository::class,
			static fn (): GroupRepository => new GroupRepository()
		);

		$container->set(
			GroupMemberRepository::class,
			static fn (): GroupMemberRepository => new GroupMemberRepository()
		);

		$container->set(
			GroupAccessRepository::class,
			static fn (): GroupAccessRepository => new GroupAccessRepository()
		);

		$container->set(
			GroupService::class,
			static function ( Container $c ): GroupService {
				$groups = $c->get( GroupRepository::class );
				assert( $groups instanceof GroupRepository );
				$members = $c->get( GroupMemberRepository::class );
				assert( $members instanceof GroupMemberRepository );
				$access = $c->get( GroupAccessRepository::class );
				assert( $access instanceof GroupAccessRepository );
				return new GroupService( $groups, $members, $access );
			}
		);

		$container->set(
			GroupEnrollmentService::class,
			static function ( Container $c ): GroupEnrollmentService {
				$members = $c->get( GroupMemberRepository::class );
				assert( $members instanceof GroupMemberRepository );
				$access = $c->get( GroupAccessRepository::class );
				assert( $access instanceof GroupAccessRepository );
				$enrollments = $c->get( EnrollmentRepository::class );
				assert( $enrollments instanceof EnrollmentRepository );
				$enrollment_service = $c->get( EnrollmentService::class );
				assert( $enrollment_service instanceof EnrollmentService );
				return new GroupEnrollmentService( $members, $access, $enrollments, $enrollment_service );
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
			EnrollmentStatsService::class,
			static function ( Container $c ): EnrollmentStatsService {
				$order = $c->get( CurriculumOrder::class );
				assert( $order instanceof CurriculumOrder );
				$progress = $c->get( ProgressRepository::class );
				assert( $progress instanceof ProgressRepository );
				$attempts = $c->get( QuizAttemptRepository::class );
				assert( $attempts instanceof QuizAttemptRepository );
				return new EnrollmentStatsService( $order, $progress, $attempts );
			}
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
				$reset_service = $c->get( ProgressResetService::class );
				assert( $reset_service instanceof ProgressResetService );
				$stats = $c->get( EnrollmentStatsService::class );
				assert( $stats instanceof EnrollmentStatsService );
				return new EnrollmentsController(
					VL_LMS_API_NAMESPACE,
					$authenticator,
					$service,
					$repository,
					$transformer,
					$reset_service,
					$stats
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
			CurriculumOrder::class,
			static fn (): CurriculumOrder => new CurriculumOrder()
		);

		$container->set(
			ProgressionGate::class,
			static function ( Container $c ): ProgressionGate {
				$order = $c->get( CurriculumOrder::class );
				assert( $order instanceof CurriculumOrder );
				$attempts = $c->get( QuizAttemptRepository::class );
				assert( $attempts instanceof QuizAttemptRepository );
				$progress = $c->get( ProgressRepository::class );
				assert( $progress instanceof ProgressRepository );
				return new ProgressionGate( $order, $attempts, $progress );
			}
		);

		$container->set(
			LessonAccessGate::class,
			static function ( Container $c ): LessonAccessGate {
				$service = $c->get( EnrollmentService::class );
				assert( $service instanceof EnrollmentService );
				$hierarchy = $c->get( EntityHierarchy::class );
				assert( $hierarchy instanceof EntityHierarchy );
				$progression = $c->get( ProgressionGate::class );
				assert( $progression instanceof ProgressionGate );
				return new LessonAccessGate( $service, $hierarchy, $progression );
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

		// ---------------------------------------------------------------
		// Curriculum endpoint (Phase 5.2)
		//
		// Personalised navigation tree with progress overlay. Composers
		// fan out from the leaf-level TopicNodeTransformer up through
		// LessonNodeTransformer, ModuleNodeTransformer, and finally
		// CurriculumTransformer; the controller wraps it all behind a
		// single GET route gated by `vl_view_lesson` and an active
		// course-level enrollment.
		// ---------------------------------------------------------------

		$container->set(
			NextEntityResolver::class,
			static fn (): NextEntityResolver => new NextEntityResolver()
		);

		$container->set(
			TopicNodeTransformer::class,
			static fn (): TopicNodeTransformer => new TopicNodeTransformer()
		);

		$container->set(
			QuizNodeTransformer::class,
			static fn (): QuizNodeTransformer => new QuizNodeTransformer()
		);

		$container->set(
			LessonNodeTransformer::class,
			static function ( Container $c ): LessonNodeTransformer {
				$topic = $c->get( TopicNodeTransformer::class );
				assert( $topic instanceof TopicNodeTransformer );
				$quiz = $c->get( QuizNodeTransformer::class );
				assert( $quiz instanceof QuizNodeTransformer );
				return new LessonNodeTransformer( $topic, $quiz );
			}
		);

		$container->set(
			ModuleNodeTransformer::class,
			static function ( Container $c ): ModuleNodeTransformer {
				$lesson = $c->get( LessonNodeTransformer::class );
				assert( $lesson instanceof LessonNodeTransformer );
				$quiz = $c->get( QuizNodeTransformer::class );
				assert( $quiz instanceof QuizNodeTransformer );
				return new ModuleNodeTransformer( $lesson, $quiz );
			}
		);

		$container->set(
			SessionNodeTransformer::class,
			static function ( Container $c ): SessionNodeTransformer {
				$attendance = $c->get( SessionAttendanceRepository::class );
				assert( $attendance instanceof SessionAttendanceRepository );
				$quiz = $c->get( QuizNodeTransformer::class );
				assert( $quiz instanceof QuizNodeTransformer );
				return new SessionNodeTransformer( $attendance, $quiz );
			}
		);

		$container->set(
			LearnCurriculumTransformer::class,
			static function ( Container $c ): LearnCurriculumTransformer {
				$module = $c->get( ModuleNodeTransformer::class );
				assert( $module instanceof ModuleNodeTransformer );
				$lesson = $c->get( LessonNodeTransformer::class );
				assert( $lesson instanceof LessonNodeTransformer );
				$session = $c->get( SessionNodeTransformer::class );
				assert( $session instanceof SessionNodeTransformer );
				$quiz = $c->get( QuizNodeTransformer::class );
				assert( $quiz instanceof QuizNodeTransformer );
				$next = $c->get( NextEntityResolver::class );
				assert( $next instanceof NextEntityResolver );
				$progress = $c->get( ProgressRepository::class );
				assert( $progress instanceof ProgressRepository );
				$quiz_attempts = $c->get( QuizAttemptRepository::class );
				assert( $quiz_attempts instanceof QuizAttemptRepository );
				$enrollments = $c->get( EnrollmentRepository::class );
				assert( $enrollments instanceof EnrollmentRepository );
				$progression = $c->get( ProgressionGate::class );
				assert( $progression instanceof ProgressionGate );
				return new LearnCurriculumTransformer( $module, $lesson, $session, $quiz, $next, $progress, $quiz_attempts, $enrollments, $progression );
			}
		);

		$container->set(
			CurriculumController::class,
			static function ( Container $c ): CurriculumController {
				$authenticator = $c->get( RestAuthenticator::class );
				assert( $authenticator instanceof RestAuthenticator );
				$service = $c->get( EnrollmentService::class );
				assert( $service instanceof EnrollmentService );
				$transformer = $c->get( LearnCurriculumTransformer::class );
				assert( $transformer instanceof LearnCurriculumTransformer );
				return new CurriculumController(
					VL_LMS_API_NAMESPACE,
					$authenticator,
					$service,
					$transformer
				);
			}
		);

		// ---------------------------------------------------------------
		// Progress write subsystem (Phase 5.3)
		//
		// Single POST endpoint that journals lesson-player events and
		// upserts vl_progress. On `event_type=complete`, the propagator
		// fans the completion up through topic → lesson → module →
		// course, recomputes vl_enrollments.progress_pct, and (for
		// final-exam-less courses at 100%) flips the enrollment to
		// COMPLETED.
		// ---------------------------------------------------------------

		$container->set(
			PositionWriteRule::class,
			static fn (): PositionWriteRule => new PositionWriteRule()
		);

		$container->set(
			CourseProgressCalculator::class,
			static function ( Container $c ): CourseProgressCalculator {
				$progress = $c->get( ProgressRepository::class );
				assert( $progress instanceof ProgressRepository );
				$enrollments = $c->get( EnrollmentRepository::class );
				assert( $enrollments instanceof EnrollmentRepository );
				$attendance = $c->get( SessionAttendanceRepository::class );
				assert( $attendance instanceof SessionAttendanceRepository );
				return new CourseProgressCalculator( $progress, $enrollments, $attendance );
			}
		);

		$container->set(
			QuizAttemptRepository::class,
			static fn (): QuizAttemptRepository => new QuizAttemptRepository()
		);

		$container->set(
			CompletionPropagator::class,
			static function ( Container $c ): CompletionPropagator {
				$progress = $c->get( ProgressRepository::class );
				assert( $progress instanceof ProgressRepository );
				$hierarchy = $c->get( EntityHierarchy::class );
				assert( $hierarchy instanceof EntityHierarchy );
				$calculator = $c->get( CourseProgressCalculator::class );
				assert( $calculator instanceof CourseProgressCalculator );
				$enrollments = $c->get( EnrollmentRepository::class );
				assert( $enrollments instanceof EnrollmentRepository );
				$quiz_attempts = $c->get( QuizAttemptRepository::class );
				assert( $quiz_attempts instanceof QuizAttemptRepository );
				return new CompletionPropagator( $progress, $hierarchy, $calculator, $enrollments, $quiz_attempts );
			}
		);

		$container->set(
			ProgressResetService::class,
			static function ( Container $c ): ProgressResetService {
				$enrollments = $c->get( EnrollmentRepository::class );
				assert( $enrollments instanceof EnrollmentRepository );
				$progress = $c->get( ProgressRepository::class );
				assert( $progress instanceof ProgressRepository );
				$quiz_attempts = $c->get( QuizAttemptRepository::class );
				assert( $quiz_attempts instanceof QuizAttemptRepository );
				return new ProgressResetService( $enrollments, $progress, $quiz_attempts );
			}
		);

		$container->set(
			SessionAttendanceProgressListener::class,
			static function ( Container $c ): SessionAttendanceProgressListener {
				$calculator = $c->get( CourseProgressCalculator::class );
				assert( $calculator instanceof CourseProgressCalculator );
				$propagator = $c->get( CompletionPropagator::class );
				assert( $propagator instanceof CompletionPropagator );
				return new SessionAttendanceProgressListener( $calculator, $propagator );
			}
		);

		$container->set(
			ProgressService::class,
			static function ( Container $c ): ProgressService {
				$progress = $c->get( ProgressRepository::class );
				assert( $progress instanceof ProgressRepository );
				$views = $c->get( LessonViewRepository::class );
				assert( $views instanceof LessonViewRepository );
				$hierarchy = $c->get( EntityHierarchy::class );
				assert( $hierarchy instanceof EntityHierarchy );
				$rule = $c->get( PositionWriteRule::class );
				assert( $rule instanceof PositionWriteRule );
				$propagator = $c->get( CompletionPropagator::class );
				assert( $propagator instanceof CompletionPropagator );
				$enrollments = $c->get( EnrollmentRepository::class );
				assert( $enrollments instanceof EnrollmentRepository );
				return new ProgressService( $progress, $views, $hierarchy, $rule, $propagator, $enrollments );
			}
		);

		$container->set(
			ProgressController::class,
			static function ( Container $c ): ProgressController {
				$authenticator = $c->get( RestAuthenticator::class );
				assert( $authenticator instanceof RestAuthenticator );
				$enrollments = $c->get( EnrollmentService::class );
				assert( $enrollments instanceof EnrollmentService );
				$hierarchy = $c->get( EntityHierarchy::class );
				assert( $hierarchy instanceof EntityHierarchy );
				$service = $c->get( ProgressService::class );
				assert( $service instanceof ProgressService );
				$progression = $c->get( ProgressionGate::class );
				assert( $progression instanceof ProgressionGate );
				return new ProgressController(
					VL_LMS_API_NAMESPACE,
					$authenticator,
					$enrollments,
					$hierarchy,
					$service,
					$progression
				);
			}
		);

		// ---------------------------------------------------------------
		// Quiz subsystem (Phase 6.1)
		//
		// Quiz attempt lifecycle: start → save_answer → submit, plus
		// fetch_state for the resume path. The four per-type scorers are
		// composed into a single QuizScoringEngine; the service sits on
		// the gate, the engine, the delivery transformer, and the
		// course-completion propagator (whose new
		// reevaluate_course_completion method is invoked on a passing
		// final-exam submit).
		// ---------------------------------------------------------------

		$container->set(
			QuizAnswerRepository::class,
			static fn (): QuizAnswerRepository => new QuizAnswerRepository()
		);

		$container->set(
			QuizCourseResolver::class,
			static fn (): QuizCourseResolver => new QuizCourseResolver()
		);

		$container->set(
			QuizAccessGate::class,
			static function ( Container $c ): QuizAccessGate {
				$enrollments = $c->get( EnrollmentService::class );
				assert( $enrollments instanceof EnrollmentService );
				$attempts = $c->get( QuizAttemptRepository::class );
				assert( $attempts instanceof QuizAttemptRepository );
				$resolver = $c->get( QuizCourseResolver::class );
				assert( $resolver instanceof QuizCourseResolver );
				$progression = $c->get( ProgressionGate::class );
				assert( $progression instanceof ProgressionGate );
				return new QuizAccessGate( $enrollments, $attempts, $resolver, $progression );
			}
		);

		$container->set(
			QuestionDeliveryTransformer::class,
			static fn (): QuestionDeliveryTransformer => new QuestionDeliveryTransformer()
		);

		$container->set(
			SingleChoiceScorer::class,
			static fn (): SingleChoiceScorer => new SingleChoiceScorer()
		);
		$container->set(
			MultipleChoiceScorer::class,
			static fn (): MultipleChoiceScorer => new MultipleChoiceScorer()
		);
		$container->set(
			TrueFalseScorer::class,
			static fn (): TrueFalseScorer => new TrueFalseScorer()
		);
		$container->set(
			TextScorer::class,
			static function ( Container $c ): TextScorer {
				$logger = $c->get( Logger::class );
				assert( $logger instanceof Logger );
				return new TextScorer( $logger );
			}
		);

		$container->set(
			QuizScoringEngine::class,
			static function ( Container $c ): QuizScoringEngine {
				$single = $c->get( SingleChoiceScorer::class );
				assert( $single instanceof SingleChoiceScorer );
				$multi = $c->get( MultipleChoiceScorer::class );
				assert( $multi instanceof MultipleChoiceScorer );
				$tf = $c->get( TrueFalseScorer::class );
				assert( $tf instanceof TrueFalseScorer );
				$text = $c->get( TextScorer::class );
				assert( $text instanceof TextScorer );
				return new QuizScoringEngine( $single, $multi, $tf, $text );
			}
		);

		$container->set(
			QuizAttemptService::class,
			static function ( Container $c ): QuizAttemptService {
				$gate = $c->get( QuizAccessGate::class );
				assert( $gate instanceof QuizAccessGate );
				$attempts = $c->get( QuizAttemptRepository::class );
				assert( $attempts instanceof QuizAttemptRepository );
				$answers = $c->get( QuizAnswerRepository::class );
				assert( $answers instanceof QuizAnswerRepository );
				$scoring = $c->get( QuizScoringEngine::class );
				assert( $scoring instanceof QuizScoringEngine );
				$delivery = $c->get( QuestionDeliveryTransformer::class );
				assert( $delivery instanceof QuestionDeliveryTransformer );
				$resolver = $c->get( QuizCourseResolver::class );
				assert( $resolver instanceof QuizCourseResolver );
				$propagator = $c->get( CompletionPropagator::class );
				assert( $propagator instanceof CompletionPropagator );
				$logger = $c->get( Logger::class );
				assert( $logger instanceof Logger );
				return new QuizAttemptService(
					$gate,
					$attempts,
					$answers,
					$scoring,
					$delivery,
					$resolver,
					$propagator,
					$logger
				);
			}
		);

		$container->set(
			QuizAttemptStateTransformer::class,
			static function ( Container $c ): QuizAttemptStateTransformer {
				$repo = $c->get( QuizAttemptRepository::class );
				assert( $repo instanceof QuizAttemptRepository );
				return new QuizAttemptStateTransformer( $repo );
			}
		);

		$container->set(
			QuizAttemptHistoryTransformer::class,
			static fn (): QuizAttemptHistoryTransformer => new QuizAttemptHistoryTransformer()
		);

		$container->set(
			QuizAttemptsController::class,
			static function ( Container $c ): QuizAttemptsController {
				$service = $c->get( QuizAttemptService::class );
				assert( $service instanceof QuizAttemptService );
				$authenticator = $c->get( RestAuthenticator::class );
				assert( $authenticator instanceof RestAuthenticator );
				$logger = $c->get( Logger::class );
				assert( $logger instanceof Logger );
				$transformer = $c->get( QuizAttemptStateTransformer::class );
				assert( $transformer instanceof QuizAttemptStateTransformer );
				$history = $c->get( QuizAttemptHistoryTransformer::class );
				assert( $history instanceof QuizAttemptHistoryTransformer );
				$enrollments = $c->get( EnrollmentRepository::class );
				assert( $enrollments instanceof EnrollmentRepository );
				return new QuizAttemptsController(
					VL_LMS_API_NAMESPACE,
					$service,
					$authenticator,
					$logger,
					$transformer,
					$history,
					$enrollments
				);
			}
		);

		// ---------------------------------------------------------------
		// Certificate subsystem (Phase 6.3)
		//
		// The auto-issuer subscribes to `vl_lms_course_completed` (fired
		// from CompletionPropagator after a successful enrollment flip)
		// and the revoker subscribes to `vl_lms_enrollment_revoked` (fired
		// from EnrollmentService::revoke). Both subscriptions are wired in
		// `boot()` after the container builds. The PDF generator + renderer
		// + QR generator power the lazy-disk-cached download path.
		// ---------------------------------------------------------------

		$container->set(
			CertificateRepository::class,
			static fn (): CertificateRepository => new CertificateRepository()
		);

		$container->set(
			SnapshotBuilder::class,
			static function ( Container $c ): SnapshotBuilder {
				$instructors = $c->get( CourseInstructorService::class );
				assert( $instructors instanceof CourseInstructorService );
				return new SnapshotBuilder( $instructors );
			}
		);

		$container->set(
			CertificateService::class,
			static function ( Container $c ): CertificateService {
				$certs = $c->get( CertificateRepository::class );
				assert( $certs instanceof CertificateRepository );
				$enrollments = $c->get( EnrollmentRepository::class );
				assert( $enrollments instanceof EnrollmentRepository );
				$snapshot = $c->get( SnapshotBuilder::class );
				assert( $snapshot instanceof SnapshotBuilder );
				$attempts = $c->get( QuizAttemptRepository::class );
				assert( $attempts instanceof QuizAttemptRepository );
				$logger = $c->get( Logger::class );
				assert( $logger instanceof Logger );
				return new CertificateService( $certs, $enrollments, $snapshot, $attempts, $logger );
			}
		);

		$container->set(
			CertificateAutoIssuer::class,
			static function ( Container $c ): CertificateAutoIssuer {
				$service = $c->get( CertificateService::class );
				assert( $service instanceof CertificateService );
				$enrollments = $c->get( EnrollmentRepository::class );
				assert( $enrollments instanceof EnrollmentRepository );
				$logger = $c->get( Logger::class );
				assert( $logger instanceof Logger );
				return new CertificateAutoIssuer( $service, $enrollments, $logger );
			}
		);

		$container->set(
			CertificateRevoker::class,
			static function ( Container $c ): CertificateRevoker {
				$service = $c->get( CertificateService::class );
				assert( $service instanceof CertificateService );
				$certs = $c->get( CertificateRepository::class );
				assert( $certs instanceof CertificateRepository );
				$logger = $c->get( Logger::class );
				assert( $logger instanceof Logger );
				return new CertificateRevoker( $service, $certs, $logger );
			}
		);

		$container->set(
			QrCodeGenerator::class,
			static fn (): QrCodeGenerator => new QrCodeGenerator()
		);

		$container->set(
			CertificateRenderer::class,
			static function ( Container $c ): CertificateRenderer {
				$qr = $c->get( QrCodeGenerator::class );
				assert( $qr instanceof QrCodeGenerator );
				$logger = $c->get( Logger::class );
				assert( $logger instanceof Logger );
				return new CertificateRenderer( $qr, $logger );
			}
		);

		$container->set(
			PdfGenerator::class,
			static function ( Container $c ): PdfGenerator {
				$renderer = $c->get( CertificateRenderer::class );
				assert( $renderer instanceof CertificateRenderer );
				$logger = $c->get( Logger::class );
				assert( $logger instanceof Logger );
				return new PdfGenerator( $renderer, $logger );
			}
		);

		$container->set(
			CertificatesController::class,
			static function ( Container $c ): CertificatesController {
				$service = $c->get( CertificateService::class );
				assert( $service instanceof CertificateService );
				$pdf = $c->get( PdfGenerator::class );
				assert( $pdf instanceof PdfGenerator );
				$repo = $c->get( CertificateRepository::class );
				assert( $repo instanceof CertificateRepository );
				$authenticator = $c->get( RestAuthenticator::class );
				assert( $authenticator instanceof RestAuthenticator );
				$logger = $c->get( Logger::class );
				assert( $logger instanceof Logger );
				return new CertificatesController(
					VL_LMS_API_NAMESPACE,
					$service,
					$pdf,
					$repo,
					$authenticator,
					$logger
				);
			}
		);

		$container->set(
			CertificateVerificationController::class,
			static function ( Container $c ): CertificateVerificationController {
				$service = $c->get( CertificateService::class );
				assert( $service instanceof CertificateService );
				return new CertificateVerificationController( VL_LMS_API_NAMESPACE, $service );
			}
		);

		// ---------------------------------------------------------------
		// Zoom integration foundations (Phase 7.0)
		//
		// Repositories for the three new tables plus the outbound-HTTP
		// stack (settings → S2S OAuth token → HTTP-bearer client). All
		// services are registered as lazy factories so a plugin boot with
		// no Zoom credentials configured never reaches `wp_remote_*`.
		// MeetingSynchronizer and the webhook controller land in 7.1+.
		// ---------------------------------------------------------------

		$container->set(
			SessionAttendanceRepository::class,
			static fn (): SessionAttendanceRepository => new SessionAttendanceRepository()
		);

		$container->set(
			WebinarRegistrationRepository::class,
			static fn (): WebinarRegistrationRepository => new WebinarRegistrationRepository()
		);

		$container->set(
			ZoomWebhookEventRepository::class,
			static fn (): ZoomWebhookEventRepository => new ZoomWebhookEventRepository()
		);

		$container->set(
			ZoomSettingsProvider::class,
			static fn (): ZoomSettingsProvider => new ZoomSettingsProvider()
		);

		$container->set(
			TokenHttpClient::class,
			static fn (): TokenHttpClient => new WpHttpTokenHttpClient()
		);

		$container->set(
			TokenProvider::class,
			static function ( Container $c ): TokenProvider {
				$settings = $c->get( ZoomSettingsProvider::class );
				assert( $settings instanceof ZoomSettingsProvider );
				$http = $c->get( TokenHttpClient::class );
				assert( $http instanceof TokenHttpClient );
				return new TokenProvider( $settings, $http );
			}
		);

		$container->set(
			ZoomApiHttpClient::class,
			static fn (): ZoomApiHttpClient => new WpRemoteZoomApiHttpClient()
		);

		$container->set(
			ZoomClient::class,
			static function ( Container $c ): ZoomClient {
				$tokens = $c->get( TokenProvider::class );
				assert( $tokens instanceof TokenProvider );
				$http = $c->get( ZoomApiHttpClient::class );
				assert( $http instanceof ZoomApiHttpClient );
				return new HttpZoomClient( $tokens, $http );
			}
		);

		// ---------------------------------------------------------------
		// Zoom meeting sync (Phase 7.1)
		//
		// Reconciles `vl_session` / `vl_webinar` posts with their Zoom
		// meetings on every save / trash / untrash. The bootstrap is
		// instantiated unconditionally; missing credentials surface as a
		// `SyncResult{decision: SKIPPED, reason: NOT_CONFIGURED}` per call.
		// ---------------------------------------------------------------

		$container->set(
			PostMetaAccessor::class,
			static fn (): PostMetaAccessor => new PostMetaAccessor()
		);

		$container->set(
			PasswordGenerator::class,
			static fn (): PasswordGenerator => new PasswordGenerator()
		);

		$container->set(
			MeetingPayloadBuilder::class,
			static function ( Container $c ): MeetingPayloadBuilder {
				$passwords = $c->get( PasswordGenerator::class );
				assert( $passwords instanceof PasswordGenerator );
				return new MeetingPayloadBuilder( $passwords );
			}
		);

		$container->set(
			DiffDetector::class,
			static function ( Container $c ): DiffDetector {
				$meta = $c->get( PostMetaAccessor::class );
				assert( $meta instanceof PostMetaAccessor );
				return new DiffDetector( $meta );
			}
		);

		$container->set(
			SyncLock::class,
			static fn (): SyncLock => new SyncLock()
		);

		$container->set(
			MeetingSynchronizer::class,
			static function ( Container $c ): MeetingSynchronizer {
				$client = $c->get( ZoomClient::class );
				assert( $client instanceof ZoomClient );
				$settings = $c->get( ZoomSettingsProvider::class );
				assert( $settings instanceof ZoomSettingsProvider );
				$meta = $c->get( PostMetaAccessor::class );
				assert( $meta instanceof PostMetaAccessor );
				$builder = $c->get( MeetingPayloadBuilder::class );
				assert( $builder instanceof MeetingPayloadBuilder );
				$diff = $c->get( DiffDetector::class );
				assert( $diff instanceof DiffDetector );
				$lock = $c->get( SyncLock::class );
				assert( $lock instanceof SyncLock );
				$logger = $c->get( Logger::class );
				assert( $logger instanceof Logger );
				return new MeetingSynchronizer( $client, $settings, $meta, $builder, $diff, $lock, $logger );
			}
		);

		$container->set(
			MeetingSynchronizerBootstrap::class,
			static function ( Container $c ): MeetingSynchronizerBootstrap {
				$synchronizer = $c->get( MeetingSynchronizer::class );
				assert( $synchronizer instanceof MeetingSynchronizer );
				return new MeetingSynchronizerBootstrap( $synchronizer );
			}
		);

		// ---------------------------------------------------------------
		// Zoom webhook receiver (Phase 7.2)
		//
		// Inbound channel from Zoom. The controller verifies HMAC-SHA256
		// signature + 5-minute replay window, parses the body, short-
		// circuits the Marketplace `endpoint.url_validation` challenge,
		// and routes operational events through WebhookEventDispatcher
		// (which dedups on x-zm-trackingid against vl_zoom_webhook_events
		// before invoking the matching handler).
		// ---------------------------------------------------------------

		$container->set(
			PostLookup::class,
			static fn (): PostLookup => new PostLookup()
		);

		$container->set(
			WebinarJoinTracker::class,
			static fn (): WebinarJoinTracker => new WebinarJoinTracker()
		);

		$container->set(
			WebhookSignatureVerifier::class,
			static function ( Container $c ): WebhookSignatureVerifier {
				$settings = $c->get( ZoomSettingsProvider::class );
				assert( $settings instanceof ZoomSettingsProvider );
				return new WebhookSignatureVerifier(
					$settings,
					static fn (): int => time()
				);
			}
		);

		$container->set(
			WebhookRequestParser::class,
			static fn (): WebhookRequestParser => new WebhookRequestParser()
		);

		$container->set(
			UrlValidationResponder::class,
			static fn (): UrlValidationResponder => new UrlValidationResponder()
		);

		$container->set(
			MeetingStartedHandler::class,
			static function ( Container $c ): MeetingStartedHandler {
				$lookup = $c->get( PostLookup::class );
				assert( $lookup instanceof PostLookup );
				$meta = $c->get( PostMetaAccessor::class );
				assert( $meta instanceof PostMetaAccessor );
				$logger = $c->get( Logger::class );
				assert( $logger instanceof Logger );
				return new MeetingStartedHandler( $lookup, $meta, $logger );
			}
		);

		$container->set(
			MeetingEndedHandler::class,
			static function ( Container $c ): MeetingEndedHandler {
				$lookup = $c->get( PostLookup::class );
				assert( $lookup instanceof PostLookup );
				$meta = $c->get( PostMetaAccessor::class );
				assert( $meta instanceof PostMetaAccessor );
				$logger = $c->get( Logger::class );
				assert( $logger instanceof Logger );
				return new MeetingEndedHandler( $lookup, $meta, $logger );
			}
		);

		$container->set(
			ParticipantJoinedHandler::class,
			static function ( Container $c ): ParticipantJoinedHandler {
				$lookup = $c->get( PostLookup::class );
				assert( $lookup instanceof PostLookup );
				$attendance = $c->get( SessionAttendanceRepository::class );
				assert( $attendance instanceof SessionAttendanceRepository );
				$tracker = $c->get( WebinarJoinTracker::class );
				assert( $tracker instanceof WebinarJoinTracker );
				$logger = $c->get( Logger::class );
				assert( $logger instanceof Logger );
				return new ParticipantJoinedHandler( $lookup, $attendance, $tracker, $logger );
			}
		);

		$container->set(
			ParticipantLeftHandler::class,
			static function ( Container $c ): ParticipantLeftHandler {
				$lookup = $c->get( PostLookup::class );
				assert( $lookup instanceof PostLookup );
				$attendance = $c->get( SessionAttendanceRepository::class );
				assert( $attendance instanceof SessionAttendanceRepository );
				$tracker = $c->get( WebinarJoinTracker::class );
				assert( $tracker instanceof WebinarJoinTracker );
				$registrations = $c->get( WebinarRegistrationRepository::class );
				assert( $registrations instanceof WebinarRegistrationRepository );
				$logger = $c->get( Logger::class );
				assert( $logger instanceof Logger );
				return new ParticipantLeftHandler(
					$lookup,
					$attendance,
					$tracker,
					$registrations,
					$logger
				);
			}
		);

		$container->set(
			RecordingCompletedHandler::class,
			static function ( Container $c ): RecordingCompletedHandler {
				$lookup = $c->get( PostLookup::class );
				assert( $lookup instanceof PostLookup );
				$logger = $c->get( Logger::class );
				assert( $logger instanceof Logger );
				return new RecordingCompletedHandler( $lookup, $logger );
			}
		);

		$container->set(
			HandlerRegistry::class,
			static function ( Container $c ): HandlerRegistry {
				$started = $c->get( MeetingStartedHandler::class );
				assert( $started instanceof MeetingStartedHandler );
				$ended = $c->get( MeetingEndedHandler::class );
				assert( $ended instanceof MeetingEndedHandler );
				$joined = $c->get( ParticipantJoinedHandler::class );
				assert( $joined instanceof ParticipantJoinedHandler );
				$left = $c->get( ParticipantLeftHandler::class );
				assert( $left instanceof ParticipantLeftHandler );
				$recording = $c->get( RecordingCompletedHandler::class );
				assert( $recording instanceof RecordingCompletedHandler );
				return new HandlerRegistry( $started, $ended, $joined, $left, $recording );
			}
		);

		$container->set(
			WebhookEventDispatcher::class,
			static function ( Container $c ): WebhookEventDispatcher {
				$repo = $c->get( ZoomWebhookEventRepository::class );
				assert( $repo instanceof ZoomWebhookEventRepository );
				$registry = $c->get( HandlerRegistry::class );
				assert( $registry instanceof HandlerRegistry );
				$logger = $c->get( Logger::class );
				assert( $logger instanceof Logger );
				return new WebhookEventDispatcher(
					$repo,
					$registry,
					$logger,
					static fn (): \DateTimeImmutable
						=> new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) )
				);
			}
		);

		$container->set(
			WebinarLookup::class,
			static fn (): WebinarLookup => new WebinarLookup()
		);

		$container->set(
			WebinarRegistrationService::class,
			static function ( Container $c ): WebinarRegistrationService {
				$lookup = $c->get( WebinarLookup::class );
				assert( $lookup instanceof WebinarLookup );
				$registrations = $c->get( WebinarRegistrationRepository::class );
				assert( $registrations instanceof WebinarRegistrationRepository );
				return new WebinarRegistrationService(
					$lookup,
					$registrations,
					static fn (): \DateTimeImmutable => new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) )
				);
			}
		);

		$container->set(
			JoinWindowPolicy::class,
			static fn (): JoinWindowPolicy => new JoinWindowPolicy()
		);

		$container->set(
			WebinarAccessGate::class,
			static function ( Container $c ): WebinarAccessGate {
				$registrations = $c->get( WebinarRegistrationRepository::class );
				assert( $registrations instanceof WebinarRegistrationRepository );
				$policy = $c->get( JoinWindowPolicy::class );
				assert( $policy instanceof JoinWindowPolicy );
				return new WebinarAccessGate(
					$registrations,
					$policy,
					static fn (): \DateTimeImmutable => new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) )
				);
			}
		);

		$container->set(
			WebinarRegistrationTransformer::class,
			static function ( Container $c ): WebinarRegistrationTransformer {
				$gate = $c->get( WebinarAccessGate::class );
				assert( $gate instanceof WebinarAccessGate );
				$cover = $c->get( CoverImageTransformer::class );
				assert( $cover instanceof CoverImageTransformer );
				$policy = $c->get( JoinWindowPolicy::class );
				assert( $policy instanceof JoinWindowPolicy );
				return new WebinarRegistrationTransformer( $gate, $cover, $policy );
			}
		);

		$container->set(
			WebinarRegistrationsController::class,
			static function ( Container $c ): WebinarRegistrationsController {
				$authenticator = $c->get( RestAuthenticator::class );
				assert( $authenticator instanceof RestAuthenticator );
				$service = $c->get( WebinarRegistrationService::class );
				assert( $service instanceof WebinarRegistrationService );
				$lookup = $c->get( WebinarLookup::class );
				assert( $lookup instanceof WebinarLookup );
				$repository = $c->get( WebinarRegistrationRepository::class );
				assert( $repository instanceof WebinarRegistrationRepository );
				$transformer = $c->get( WebinarRegistrationTransformer::class );
				assert( $transformer instanceof WebinarRegistrationTransformer );
				return new WebinarRegistrationsController(
					VL_LMS_API_NAMESPACE,
					$authenticator,
					$service,
					$lookup,
					$repository,
					$transformer,
					static fn (): \DateTimeImmutable => new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) )
				);
			}
		);

		$container->set(
			WebinarAccessController::class,
			static function ( Container $c ): WebinarAccessController {
				$authenticator = $c->get( RestAuthenticator::class );
				assert( $authenticator instanceof RestAuthenticator );
				$lookup = $c->get( WebinarLookup::class );
				assert( $lookup instanceof WebinarLookup );
				$gate = $c->get( WebinarAccessGate::class );
				assert( $gate instanceof WebinarAccessGate );
				return new WebinarAccessController(
					VL_LMS_API_NAMESPACE,
					$authenticator,
					$lookup,
					$gate
				);
			}
		);

		$container->set(
			SessionLookup::class,
			static fn (): SessionLookup => new SessionLookup()
		);

		$container->set(
			SessionAccessGate::class,
			static function ( Container $c ): SessionAccessGate {
				$enrollments = $c->get( EnrollmentService::class );
				assert( $enrollments instanceof EnrollmentService );
				$policy = $c->get( JoinWindowPolicy::class );
				assert( $policy instanceof JoinWindowPolicy );
				return new SessionAccessGate(
					$enrollments,
					$policy,
					static fn (): \DateTimeImmutable => new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) )
				);
			}
		);

		$container->set(
			SessionContentTransformer::class,
			static function ( Container $c ): SessionContentTransformer {
				$gate = $c->get( SessionAccessGate::class );
				assert( $gate instanceof SessionAccessGate );
				$attendance = $c->get( SessionAttendanceRepository::class );
				assert( $attendance instanceof SessionAttendanceRepository );
				$policy = $c->get( JoinWindowPolicy::class );
				assert( $policy instanceof JoinWindowPolicy );
				return new SessionContentTransformer(
					$gate,
					$attendance,
					$policy,
					static fn (): \DateTimeImmutable => new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) )
				);
			}
		);

		$container->set(
			SessionAccessController::class,
			static function ( Container $c ): SessionAccessController {
				$authenticator = $c->get( RestAuthenticator::class );
				assert( $authenticator instanceof RestAuthenticator );
				$lookup = $c->get( SessionLookup::class );
				assert( $lookup instanceof SessionLookup );
				$gate = $c->get( SessionAccessGate::class );
				assert( $gate instanceof SessionAccessGate );
				$transformer = $c->get( SessionContentTransformer::class );
				assert( $transformer instanceof SessionContentTransformer );
				$enrollments = $c->get( EnrollmentService::class );
				assert( $enrollments instanceof EnrollmentService );
				return new SessionAccessController(
					VL_LMS_API_NAMESPACE,
					$authenticator,
					$lookup,
					$gate,
					$transformer,
					$enrollments
				);
			}
		);

		// Phase 7.6 — transactional mail + reminder cron wiring.
		$container->set(
			AppUrlResolver::class,
			static function ( Container $c ): AppUrlResolver {
				$logger = $c->get( Logger::class );
				assert( $logger instanceof Logger );
				return new AppUrlResolver( $logger );
			}
		);

		$container->set(
			HtmlMailSender::class,
			static fn (): HtmlMailSender => new HtmlMailSender()
		);

		$container->set(
			SessionReminderMailer::class,
			static function ( Container $c ): SessionReminderMailer {
				$logger = $c->get( Logger::class );
				assert( $logger instanceof Logger );
				$resolver = $c->get( AppUrlResolver::class );
				assert( $resolver instanceof AppUrlResolver );
				$sender = $c->get( HtmlMailSender::class );
				assert( $sender instanceof HtmlMailSender );
				return new SessionReminderMailer( $logger, $resolver, $sender );
			}
		);

		$container->set(
			WebinarReminderMailer::class,
			static function ( Container $c ): WebinarReminderMailer {
				$logger = $c->get( Logger::class );
				assert( $logger instanceof Logger );
				$resolver = $c->get( AppUrlResolver::class );
				assert( $resolver instanceof AppUrlResolver );
				$sender = $c->get( HtmlMailSender::class );
				assert( $sender instanceof HtmlMailSender );
				return new WebinarReminderMailer( $logger, $resolver, $sender );
			}
		);

		$container->set(
			RecordingReadyMailer::class,
			static function ( Container $c ): RecordingReadyMailer {
				$logger = $c->get( Logger::class );
				assert( $logger instanceof Logger );
				$resolver = $c->get( AppUrlResolver::class );
				assert( $resolver instanceof AppUrlResolver );
				$sender = $c->get( HtmlMailSender::class );
				assert( $sender instanceof HtmlMailSender );
				return new RecordingReadyMailer( $logger, $resolver, $sender );
			}
		);

		$container->set(
			CertificateIssuedMailer::class,
			static function ( Container $c ): CertificateIssuedMailer {
				$logger = $c->get( Logger::class );
				assert( $logger instanceof Logger );
				$certs = $c->get( CertificateRepository::class );
				assert( $certs instanceof CertificateRepository );
				$resolver = $c->get( AppUrlResolver::class );
				assert( $resolver instanceof AppUrlResolver );
				$sender = $c->get( HtmlMailSender::class );
				assert( $sender instanceof HtmlMailSender );
				return new CertificateIssuedMailer( $logger, $certs, $resolver, $sender );
			}
		);

		$container->set(
			ReminderScheduler::class,
			static function ( Container $c ): ReminderScheduler {
				$logger = $c->get( Logger::class );
				assert( $logger instanceof Logger );
				return new ReminderScheduler(
					$logger,
					static fn (): \DateTimeImmutable
						=> new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) )
				);
			}
		);

		$container->set(
			ReminderDispatcher::class,
			static function ( Container $c ): ReminderDispatcher {
				$session_mailer = $c->get( SessionReminderMailer::class );
				assert( $session_mailer instanceof SessionReminderMailer );
				$webinar_mailer = $c->get( WebinarReminderMailer::class );
				assert( $webinar_mailer instanceof WebinarReminderMailer );
				$registrations = $c->get( WebinarRegistrationRepository::class );
				assert( $registrations instanceof WebinarRegistrationRepository );
				$enrollments = $c->get( EnrollmentRepository::class );
				assert( $enrollments instanceof EnrollmentRepository );
				$logger = $c->get( Logger::class );
				assert( $logger instanceof Logger );
				return new ReminderDispatcher(
					$session_mailer,
					$webinar_mailer,
					$registrations,
					$enrollments,
					$logger,
					static fn (): \DateTimeImmutable
						=> new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) )
				);
			}
		);

		$container->set(
			RecordingReadyListener::class,
			static function ( Container $c ): RecordingReadyListener {
				$mailer = $c->get( RecordingReadyMailer::class );
				assert( $mailer instanceof RecordingReadyMailer );
				$registrations = $c->get( WebinarRegistrationRepository::class );
				assert( $registrations instanceof WebinarRegistrationRepository );
				$enrollments = $c->get( EnrollmentRepository::class );
				assert( $enrollments instanceof EnrollmentRepository );
				$logger = $c->get( Logger::class );
				assert( $logger instanceof Logger );
				return new RecordingReadyListener( $mailer, $registrations, $enrollments, $logger );
			}
		);

		$container->set(
			CertificateIssuedListener::class,
			static function ( Container $c ): CertificateIssuedListener {
				$mailer = $c->get( CertificateIssuedMailer::class );
				assert( $mailer instanceof CertificateIssuedMailer );
				$logger = $c->get( Logger::class );
				assert( $logger instanceof Logger );
				return new CertificateIssuedListener( $mailer, $logger );
			}
		);

		$container->set(
			OrderPaidMailer::class,
			static function ( Container $c ): OrderPaidMailer {
				$logger = $c->get( Logger::class );
				assert( $logger instanceof Logger );
				$resolver = $c->get( AppUrlResolver::class );
				assert( $resolver instanceof AppUrlResolver );
				$sender = $c->get( HtmlMailSender::class );
				assert( $sender instanceof HtmlMailSender );
				return new OrderPaidMailer( $logger, $resolver, $sender );
			}
		);

		$container->set(
			OrderRefundedMailer::class,
			static function ( Container $c ): OrderRefundedMailer {
				$logger = $c->get( Logger::class );
				assert( $logger instanceof Logger );
				$resolver = $c->get( AppUrlResolver::class );
				assert( $resolver instanceof AppUrlResolver );
				$sender = $c->get( HtmlMailSender::class );
				assert( $sender instanceof HtmlMailSender );
				return new OrderRefundedMailer( $logger, $resolver, $sender );
			}
		);

		$container->set(
			OrderFailedMailer::class,
			static function ( Container $c ): OrderFailedMailer {
				$logger = $c->get( Logger::class );
				assert( $logger instanceof Logger );
				$resolver = $c->get( AppUrlResolver::class );
				assert( $resolver instanceof AppUrlResolver );
				$sender = $c->get( HtmlMailSender::class );
				assert( $sender instanceof HtmlMailSender );
				return new OrderFailedMailer( $logger, $resolver, $sender );
			}
		);

		$container->set(
			CourseAccessGrantedMailer::class,
			static function ( Container $c ): CourseAccessGrantedMailer {
				$logger = $c->get( Logger::class );
				assert( $logger instanceof Logger );
				$resolver = $c->get( AppUrlResolver::class );
				assert( $resolver instanceof AppUrlResolver );
				$sender = $c->get( HtmlMailSender::class );
				assert( $sender instanceof HtmlMailSender );
				return new CourseAccessGrantedMailer( $logger, $resolver, $sender );
			}
		);

		$container->set(
			OrderPaidListener::class,
			static function ( Container $c ): OrderPaidListener {
				$mailer = $c->get( OrderPaidMailer::class );
				assert( $mailer instanceof OrderPaidMailer );
				$logger = $c->get( Logger::class );
				assert( $logger instanceof Logger );
				return new OrderPaidListener( $mailer, $logger );
			}
		);

		$container->set(
			OrderRefundedListener::class,
			static function ( Container $c ): OrderRefundedListener {
				$mailer = $c->get( OrderRefundedMailer::class );
				assert( $mailer instanceof OrderRefundedMailer );
				$logger = $c->get( Logger::class );
				assert( $logger instanceof Logger );
				return new OrderRefundedListener( $mailer, $logger );
			}
		);

		$container->set(
			OrderFailedListener::class,
			static function ( Container $c ): OrderFailedListener {
				$mailer = $c->get( OrderFailedMailer::class );
				assert( $mailer instanceof OrderFailedMailer );
				$logger = $c->get( Logger::class );
				assert( $logger instanceof Logger );
				return new OrderFailedListener( $mailer, $logger );
			}
		);

		$container->set(
			ZoomWebhookController::class,
			static function ( Container $c ): ZoomWebhookController {
				$verifier = $c->get( WebhookSignatureVerifier::class );
				assert( $verifier instanceof WebhookSignatureVerifier );
				$parser = $c->get( WebhookRequestParser::class );
				assert( $parser instanceof WebhookRequestParser );
				$responder = $c->get( UrlValidationResponder::class );
				assert( $responder instanceof UrlValidationResponder );
				$dispatcher = $c->get( WebhookEventDispatcher::class );
				assert( $dispatcher instanceof WebhookEventDispatcher );
				$settings = $c->get( ZoomSettingsProvider::class );
				assert( $settings instanceof ZoomSettingsProvider );
				$logger = $c->get( Logger::class );
				assert( $logger instanceof Logger );
				return new ZoomWebhookController(
					VL_LMS_API_NAMESPACE,
					$verifier,
					$parser,
					$responder,
					$dispatcher,
					$settings,
					$logger
				);
			}
		);

		// Phase 8.1 — order service + LiqPay outbound + REST.
		$container->set(
			OrderRepository::class,
			static fn (): OrderRepository => new OrderRepository()
		);

		$container->set(
			LiqPaySettings::class,
			static fn (): LiqPaySettings => new LiqPaySettings()
		);

		$container->set(
			LiqPaySignatureBuilder::class,
			static fn (): LiqPaySignatureBuilder => new LiqPaySignatureBuilder()
		);

		$container->set(
			LiqPayPayloadBuilder::class,
			static function ( Container $c ): LiqPayPayloadBuilder {
				$settings = $c->get( LiqPaySettings::class );
				assert( $settings instanceof LiqPaySettings );
				$resolver = $c->get( AppUrlResolver::class );
				assert( $resolver instanceof AppUrlResolver );
				return new LiqPayPayloadBuilder( $settings, $resolver );
			}
		);

		$container->set(
			LiqPayHttpClient::class,
			static fn (): LiqPayHttpClient => new LiqPayHttpClient()
		);

		$container->set(
			LiqPayRefundResponseParser::class,
			static fn (): LiqPayRefundResponseParser => new LiqPayRefundResponseParser()
		);

		$container->set(
			LiqPayClient::class,
			static function ( Container $c ): LiqPayClient {
				$settings = $c->get( LiqPaySettings::class );
				assert( $settings instanceof LiqPaySettings );
				$payload = $c->get( LiqPayPayloadBuilder::class );
				assert( $payload instanceof LiqPayPayloadBuilder );
				$signature = $c->get( LiqPaySignatureBuilder::class );
				assert( $signature instanceof LiqPaySignatureBuilder );
				$http_client = $c->get( LiqPayHttpClient::class );
				assert( $http_client instanceof LiqPayHttpClient );
				$refund_parser = $c->get( LiqPayRefundResponseParser::class );
				assert( $refund_parser instanceof LiqPayRefundResponseParser );
				return new LiqPayClient( $settings, $payload, $signature, $http_client, $refund_parser );
			}
		);

		$container->set(
			PaymentProvider::class,
			static function ( Container $c ): PaymentProvider {
				$client = $c->get( LiqPayClient::class );
				assert( $client instanceof LiqPayClient );
				return $client;
			}
		);

		$container->set(
			RefundCapableProvider::class,
			static function ( Container $c ): RefundCapableProvider {
				$client = $c->get( LiqPayClient::class );
				assert( $client instanceof LiqPayClient );
				return $client;
			}
		);

		$container->set(
			PriceResolver::class,
			static fn (): PriceResolver => new PriceResolver()
		);

		$container->set(
			PurchasableLookup::class,
			static fn (): PurchasableLookup => new PurchasableLookup()
		);

		$container->set(
			OrderService::class,
			static function ( Container $c ): OrderService {
				$orders = $c->get( OrderRepository::class );
				assert( $orders instanceof OrderRepository );
				$prices = $c->get( PriceResolver::class );
				assert( $prices instanceof PriceResolver );
				$lookup = $c->get( PurchasableLookup::class );
				assert( $lookup instanceof PurchasableLookup );
				$enrollments = $c->get( EnrollmentService::class );
				assert( $enrollments instanceof EnrollmentService );
				$webinars = $c->get( WebinarRegistrationService::class );
				assert( $webinars instanceof WebinarRegistrationService );
				$provider = $c->get( PaymentProvider::class );
				assert( $provider instanceof PaymentProvider );
				return new OrderService(
					$orders,
					$prices,
					$lookup,
					$enrollments,
					$webinars,
					$provider
				);
			}
		);

		$container->set(
			OrderTransformer::class,
			static fn (): OrderTransformer => new OrderTransformer()
		);

		$container->set(
			PreparedPaymentTransformer::class,
			static fn (): PreparedPaymentTransformer => new PreparedPaymentTransformer()
		);

		$container->set(
			OrdersController::class,
			static function ( Container $c ): OrdersController {
				$authenticator = $c->get( RestAuthenticator::class );
				assert( $authenticator instanceof RestAuthenticator );
				$service = $c->get( OrderService::class );
				assert( $service instanceof OrderService );
				$order_transformer = $c->get( OrderTransformer::class );
				assert( $order_transformer instanceof OrderTransformer );
				$payment_transformer = $c->get( PreparedPaymentTransformer::class );
				assert( $payment_transformer instanceof PreparedPaymentTransformer );
				return new OrdersController(
					VL_LMS_API_NAMESPACE,
					$authenticator,
					$service,
					$order_transformer,
					$payment_transformer
				);
			}
		);

		// --- Phase 8.2 — LiqPay inbound + fan-out + expiration cron ---

		$container->set(
			PaymentRepository::class,
			static fn (): PaymentRepository => new PaymentRepository()
		);

		$container->set(
			LiqPaySignatureVerifier::class,
			static function ( Container $c ): LiqPaySignatureVerifier {
				$settings = $c->get( LiqPaySettings::class );
				assert( $settings instanceof LiqPaySettings );
				$builder = $c->get( LiqPaySignatureBuilder::class );
				assert( $builder instanceof LiqPaySignatureBuilder );
				return new LiqPaySignatureVerifier( $settings, $builder );
			}
		);

		$container->set(
			LiqPayCallbackParser::class,
			static function ( Container $c ): LiqPayCallbackParser {
				$verifier = $c->get( LiqPaySignatureVerifier::class );
				assert( $verifier instanceof LiqPaySignatureVerifier );
				return new LiqPayCallbackParser( $verifier );
			}
		);

		$container->set(
			LiqPayCallbackHandler::class,
			static function ( Container $c ): LiqPayCallbackHandler {
				$orders = $c->get( OrderRepository::class );
				assert( $orders instanceof OrderRepository );
				$payments = $c->get( PaymentRepository::class );
				assert( $payments instanceof PaymentRepository );
				$logger = $c->get( Logger::class );
				assert( $logger instanceof Logger );
				return new LiqPayCallbackHandler( $orders, $payments, $logger );
			}
		);

		$container->set(
			PaymentsController::class,
			static function ( Container $c ): PaymentsController {
				$parser = $c->get( LiqPayCallbackParser::class );
				assert( $parser instanceof LiqPayCallbackParser );
				$handler = $c->get( LiqPayCallbackHandler::class );
				assert( $handler instanceof LiqPayCallbackHandler );
				$logger = $c->get( Logger::class );
				assert( $logger instanceof Logger );
				return new PaymentsController(
					VL_LMS_API_NAMESPACE,
					$parser,
					$handler,
					$logger
				);
			}
		);

		$container->set(
			OrderEnrollmentFanout::class,
			static function ( Container $c ): OrderEnrollmentFanout {
				$enrollments = $c->get( EnrollmentService::class );
				assert( $enrollments instanceof EnrollmentService );
				$webinars = $c->get( WebinarRegistrationService::class );
				assert( $webinars instanceof WebinarRegistrationService );
				$logger = $c->get( Logger::class );
				assert( $logger instanceof Logger );
				return new OrderEnrollmentFanout( $enrollments, $webinars, $logger );
			}
		);

		$container->set(
			OrderExpirationCron::class,
			static function ( Container $c ): OrderExpirationCron {
				$orders = $c->get( OrderRepository::class );
				assert( $orders instanceof OrderRepository );
				$logger = $c->get( Logger::class );
				assert( $logger instanceof Logger );
				return new OrderExpirationCron( $orders, $logger );
			}
		);

		// --- Phase 8.3 — refund flow + admin REST + reversed callback ---

		$container->set(
			RefundService::class,
			static function ( Container $c ): RefundService {
				$orders = $c->get( OrderRepository::class );
				assert( $orders instanceof OrderRepository );
				$payments = $c->get( PaymentRepository::class );
				assert( $payments instanceof PaymentRepository );
				$provider = $c->get( RefundCapableProvider::class );
				assert( $provider instanceof RefundCapableProvider );
				$logger = $c->get( Logger::class );
				assert( $logger instanceof Logger );
				return new RefundService( $orders, $payments, $provider, $logger );
			}
		);

		$container->set(
			OrderRefundEnrollmentRevoker::class,
			static function ( Container $c ): OrderRefundEnrollmentRevoker {
				$enrollments = $c->get( EnrollmentService::class );
				assert( $enrollments instanceof EnrollmentService );
				$webinars = $c->get( WebinarRegistrationService::class );
				assert( $webinars instanceof WebinarRegistrationService );
				$logger = $c->get( Logger::class );
				assert( $logger instanceof Logger );
				return new OrderRefundEnrollmentRevoker( $enrollments, $webinars, $logger );
			}
		);

		// --- Phase 8.6 — admin orders screen ---

		$container->set(
			OrdersListPage::class,
			static function ( Container $c ): OrdersListPage {
				$orders = $c->get( OrderRepository::class );
				assert( $orders instanceof OrderRepository );
				return new OrdersListPage( $orders );
			}
		);

		$container->set(
			OrderDetailPage::class,
			static function ( Container $c ): OrderDetailPage {
				$orders = $c->get( OrderRepository::class );
				assert( $orders instanceof OrderRepository );
				$payments = $c->get( PaymentRepository::class );
				assert( $payments instanceof PaymentRepository );
				$refunds = $c->get( RefundService::class );
				assert( $refunds instanceof RefundService );
				return new OrderDetailPage( $orders, $payments, $refunds );
			}
		);

		$container->set(
			AdminOrdersController::class,
			static function ( Container $c ): AdminOrdersController {
				$authenticator = $c->get( RestAuthenticator::class );
				assert( $authenticator instanceof RestAuthenticator );
				$refunds = $c->get( RefundService::class );
				assert( $refunds instanceof RefundService );
				$transformer = $c->get( OrderTransformer::class );
				assert( $transformer instanceof OrderTransformer );
				return new AdminOrdersController(
					VL_LMS_API_NAMESPACE,
					$authenticator,
					$refunds,
					$transformer
				);
			}
		);

		// --- Phase 9.0 — typed CPT meta-boxes + co-instructor UI ---

		$container->set( CourseMetaBox::class, static fn (): CourseMetaBox => new CourseMetaBox() );
		$container->set( ModuleMetaBox::class, static fn (): ModuleMetaBox => new ModuleMetaBox() );
		$container->set( LessonMetaBox::class, static fn (): LessonMetaBox => new LessonMetaBox() );
		$container->set( TopicMetaBox::class, static fn (): TopicMetaBox => new TopicMetaBox() );
		$container->set( SessionMetaBox::class, static fn (): SessionMetaBox => new SessionMetaBox() );
		$container->set( WebinarMetaBox::class, static fn (): WebinarMetaBox => new WebinarMetaBox() );
		$container->set( QuizMetaBox::class, static fn (): QuizMetaBox => new QuizMetaBox() );
		$container->set( QuizQuestionMetaBox::class, static fn (): QuizQuestionMetaBox => new QuizQuestionMetaBox() );
		$container->set( AssignmentMetaBox::class, static fn (): AssignmentMetaBox => new AssignmentMetaBox() );

		$container->set(
			CourseInstructorsMetaBox::class,
			static function ( Container $c ): CourseInstructorsMetaBox {
				$service = $c->get( CourseInstructorService::class );
				assert( $service instanceof CourseInstructorService );
				$repo = $c->get( CourseInstructorRepository::class );
				assert( $repo instanceof CourseInstructorRepository );
				return new CourseInstructorsMetaBox( $service, $repo );
			}
		);

		// --- Phase 9.1 — drag-drop reorder ---

		$container->set( ModuleListMetaBox::class, static fn (): ModuleListMetaBox => new ModuleListMetaBox() );
		$container->set( SessionListMetaBox::class, static fn (): SessionListMetaBox => new SessionListMetaBox() );
		$container->set( LessonListMetaBox::class, static fn (): LessonListMetaBox => new LessonListMetaBox() );
		$container->set( CourseLessonListMetaBox::class, static fn (): CourseLessonListMetaBox => new CourseLessonListMetaBox() );
		$container->set( TopicListMetaBox::class, static fn (): TopicListMetaBox => new TopicListMetaBox() );
		$container->set( QuestionListMetaBox::class, static fn (): QuestionListMetaBox => new QuestionListMetaBox() );
		$container->set( ReorderAjaxHandler::class, static fn (): ReorderAjaxHandler => new ReorderAjaxHandler() );
		$container->set( LessonPickerAjaxHandler::class, static fn (): LessonPickerAjaxHandler => new LessonPickerAjaxHandler() );
		$container->set( TopicPickerAjaxHandler::class, static fn (): TopicPickerAjaxHandler => new TopicPickerAjaxHandler() );
		$container->set( QuestionPickerAjaxHandler::class, static fn (): QuestionPickerAjaxHandler => new QuestionPickerAjaxHandler() );
		$container->set( QuizPickerAjaxHandler::class, static fn (): QuizPickerAjaxHandler => new QuizPickerAjaxHandler() );
		$container->set( AssignmentPickerAjaxHandler::class, static fn (): AssignmentPickerAjaxHandler => new AssignmentPickerAjaxHandler() );

		$container->set(
			AdminProvider::class,
			static function ( Container $c ): AdminProvider {
				$boxes            = [
					$c->get( CourseMetaBox::class ),
					// Author (lead instructor) selector — one instance per
					// post type whose post_author AuthorSyncService mirrors.
					new AuthorMetaBox( 'vl_course' ),
					new AuthorMetaBox( 'vl_webinar' ),
					$c->get( CourseInstructorsMetaBox::class ),
					$c->get( ModuleMetaBox::class ),
					$c->get( LessonMetaBox::class ),
					$c->get( TopicMetaBox::class ),
					$c->get( SessionMetaBox::class ),
					$c->get( WebinarMetaBox::class ),
					$c->get( QuizMetaBox::class ),
					$c->get( QuizQuestionMetaBox::class ),
					$c->get( AssignmentMetaBox::class ),
				];
				$child_list_boxes = [
					$c->get( ModuleListMetaBox::class ),
					$c->get( CourseLessonListMetaBox::class ),
					$c->get( SessionListMetaBox::class ),
					$c->get( LessonListMetaBox::class ),
					$c->get( TopicListMetaBox::class ),
					$c->get( QuestionListMetaBox::class ),
					// Quiz / assignment reverse-list boxes — one instance per
					// flexible parent type (course / module / lesson / session).
					new QuizListMetaBox( 'vl_course' ),
					new QuizListMetaBox( 'vl_module' ),
					new QuizListMetaBox( 'vl_lesson' ),
					new QuizListMetaBox( 'vl_session' ),
					new AssignmentListMetaBox( 'vl_course' ),
					new AssignmentListMetaBox( 'vl_module' ),
					new AssignmentListMetaBox( 'vl_lesson' ),
					new AssignmentListMetaBox( 'vl_session' ),
				];
				$reorder_handler  = $c->get( ReorderAjaxHandler::class );
				assert( $reorder_handler instanceof ReorderAjaxHandler );
				$menu_provider = $c->get( AdminMenuProvider::class );
				assert( $menu_provider instanceof AdminMenuProvider );
				$lesson_picker = $c->get( LessonPickerAjaxHandler::class );
				assert( $lesson_picker instanceof LessonPickerAjaxHandler );
				$topic_picker = $c->get( TopicPickerAjaxHandler::class );
				assert( $topic_picker instanceof TopicPickerAjaxHandler );
				$question_picker = $c->get( QuestionPickerAjaxHandler::class );
				assert( $question_picker instanceof QuestionPickerAjaxHandler );
				$quiz_picker = $c->get( QuizPickerAjaxHandler::class );
				assert( $quiz_picker instanceof QuizPickerAjaxHandler );
				$assignment_picker = $c->get( AssignmentPickerAjaxHandler::class );
				assert( $assignment_picker instanceof AssignmentPickerAjaxHandler );
				/** @var list<\VL\LMS\Admin\MetaBoxes\AbstractMetaBox> $boxes */
				/** @var list<\VL\LMS\Admin\MetaBoxes\ChildList\AbstractChildListMetaBox> $child_list_boxes */
				return new AdminProvider( $boxes, $child_list_boxes, $reorder_handler, $menu_provider, null, $lesson_picker, $topic_picker, $question_picker, $quiz_picker, $assignment_picker );
			}
		);

		$container->set(
			CurriculumListColumns::class,
			static fn (): CurriculumListColumns => new CurriculumListColumns()
		);

		// --- Phase 9.2 — top-level wp-admin menu, instructor dashboard, preview API ---

		$container->set(
			CourseStatsQuery::class,
			static fn (): CourseStatsQuery => new CourseStatsQuery()
		);

		$container->set(
			InstructorDashboardPage::class,
			static function ( Container $c ): InstructorDashboardPage {
				$instructors = $c->get( CourseInstructorRepository::class );
				assert( $instructors instanceof CourseInstructorRepository );
				$stats = $c->get( CourseStatsQuery::class );
				assert( $stats instanceof CourseStatsQuery );
				return new InstructorDashboardPage( $instructors, $stats );
			}
		);

		$container->set(
			GroupDetailPage::class,
			static function ( Container $c ): GroupDetailPage {
				$groups = $c->get( GroupRepository::class );
				assert( $groups instanceof GroupRepository );
				$members = $c->get( GroupMemberRepository::class );
				assert( $members instanceof GroupMemberRepository );
				$access = $c->get( GroupAccessRepository::class );
				assert( $access instanceof GroupAccessRepository );
				return new GroupDetailPage( $groups, $members, $access );
			}
		);

		$container->set(
			GroupsListPage::class,
			static function ( Container $c ): GroupsListPage {
				$groups = $c->get( GroupRepository::class );
				assert( $groups instanceof GroupRepository );
				$members = $c->get( GroupMemberRepository::class );
				assert( $members instanceof GroupMemberRepository );
				$access = $c->get( GroupAccessRepository::class );
				assert( $access instanceof GroupAccessRepository );
				$detail = $c->get( GroupDetailPage::class );
				assert( $detail instanceof GroupDetailPage );
				return new GroupsListPage( $groups, $members, $access, $detail );
			}
		);

		$container->set(
			GroupFormHandler::class,
			static function ( Container $c ): GroupFormHandler {
				$service = $c->get( GroupService::class );
				assert( $service instanceof GroupService );
				$fanout = $c->get( GroupEnrollmentService::class );
				assert( $fanout instanceof GroupEnrollmentService );
				$groups = $c->get( GroupRepository::class );
				assert( $groups instanceof GroupRepository );
				$members = $c->get( GroupMemberRepository::class );
				assert( $members instanceof GroupMemberRepository );
				return new GroupFormHandler(
					$service,
					$fanout,
					$groups,
					$members,
					new CyrillicTransliterator()
				);
			}
		);

		$container->set(
			StudentDetailPage::class,
			static function ( Container $c ): StudentDetailPage {
				$enrollments = $c->get( EnrollmentRepository::class );
				assert( $enrollments instanceof EnrollmentRepository );
				$certificates = $c->get( CertificateRepository::class );
				assert( $certificates instanceof CertificateRepository );
				return new StudentDetailPage( $enrollments, $certificates );
			}
		);

		$container->set(
			StudentEnrollmentFormHandler::class,
			static function ( Container $c ): StudentEnrollmentFormHandler {
				$service = $c->get( EnrollmentService::class );
				assert( $service instanceof EnrollmentService );
				$repository = $c->get( EnrollmentRepository::class );
				assert( $repository instanceof EnrollmentRepository );
				$mailer = $c->get( CourseAccessGrantedMailer::class );
				assert( $mailer instanceof CourseAccessGrantedMailer );
				return new StudentEnrollmentFormHandler( $service, $repository, $mailer );
			}
		);

		$container->set(
			StudentsListPage::class,
			static function ( Container $c ): StudentsListPage {
				$groups = $c->get( GroupRepository::class );
				assert( $groups instanceof GroupRepository );
				$members = $c->get( GroupMemberRepository::class );
				assert( $members instanceof GroupMemberRepository );
				$enrollments = $c->get( EnrollmentRepository::class );
				assert( $enrollments instanceof EnrollmentRepository );
				$detail = $c->get( StudentDetailPage::class );
				assert( $detail instanceof StudentDetailPage );
				$quiz_attempts = $c->get( QuizAttemptRepository::class );
				assert( $quiz_attempts instanceof QuizAttemptRepository );
				return new StudentsListPage( $groups, $members, $enrollments, $detail, $quiz_attempts );
			}
		);

		$container->set(
			AdminMenuProvider::class,
			static function ( Container $c ): AdminMenuProvider {
				$dashboard = $c->get( InstructorDashboardPage::class );
				assert( $dashboard instanceof InstructorDashboardPage );
				$orders_page = $c->get( OrdersListPage::class );
				assert( $orders_page instanceof OrdersListPage );
				$analytics_page = $c->get( AnalyticsPage::class );
				assert( $analytics_page instanceof AnalyticsPage );
				$grading_page = $c->get( GradingQueuePage::class );
				assert( $grading_page instanceof GradingQueuePage );
				$settings_page = $c->get( SettingsPage::class );
				assert( $settings_page instanceof SettingsPage );
				$groups_page = $c->get( GroupsListPage::class );
				assert( $groups_page instanceof GroupsListPage );
				$students_page = $c->get( StudentsListPage::class );
				assert( $students_page instanceof StudentsListPage );
				return new AdminMenuProvider(
					$dashboard,
					$orders_page,
					$analytics_page,
					$grading_page,
					$settings_page,
					$groups_page,
					$students_page
				);
			}
		);

		$container->set(
			AdminPreviewController::class,
			static fn (): AdminPreviewController => new AdminPreviewController( VL_LMS_API_NAMESPACE )
		);

		// --- Phase 9.3 — daily analytics rollup + admin page ---

		$container->set(
			AnalyticsRollupService::class,
			static fn (): AnalyticsRollupService => new AnalyticsRollupService()
		);

		$container->set(
			AnalyticsCron::class,
			static function ( Container $c ): AnalyticsCron {
				$rollup = $c->get( AnalyticsRollupService::class );
				assert( $rollup instanceof AnalyticsRollupService );
				return new AnalyticsCron( $rollup );
			}
		);

		$container->set(
			AnalyticsPage::class,
			static fn (): AnalyticsPage => new AnalyticsPage()
		);

		// --- Phase 9.4 — assignment submissions, grading service + queue ---

		$container->set(
			AssignmentSubmissionRepository::class,
			static fn (): AssignmentSubmissionRepository => new AssignmentSubmissionRepository()
		);

		$container->set(
			SubmissionTransformer::class,
			static fn (): SubmissionTransformer => new SubmissionTransformer()
		);

		$container->set(
			AssignmentSubmissionService::class,
			static function ( Container $c ): AssignmentSubmissionService {
				$repo = $c->get( AssignmentSubmissionRepository::class );
				assert( $repo instanceof AssignmentSubmissionRepository );
				$enrollment = $c->get( EnrollmentService::class );
				assert( $enrollment instanceof EnrollmentService );
				$hierarchy = $c->get( EntityHierarchy::class );
				assert( $hierarchy instanceof EntityHierarchy );
				return new AssignmentSubmissionService( $repo, $enrollment, $hierarchy );
			}
		);

		$container->set(
			AssignmentCompletionListener::class,
			static function ( Container $c ): AssignmentCompletionListener {
				$hierarchy = $c->get( EntityHierarchy::class );
				assert( $hierarchy instanceof EntityHierarchy );
				$propagator = $c->get( CompletionPropagator::class );
				assert( $propagator instanceof CompletionPropagator );
				return new AssignmentCompletionListener( $hierarchy, $propagator );
			}
		);

		$container->set(
			AssignmentsController::class,
			static function ( Container $c ): AssignmentsController {
				$service = $c->get( AssignmentSubmissionService::class );
				assert( $service instanceof AssignmentSubmissionService );
				$repo = $c->get( AssignmentSubmissionRepository::class );
				assert( $repo instanceof AssignmentSubmissionRepository );
				$transformer = $c->get( SubmissionTransformer::class );
				assert( $transformer instanceof SubmissionTransformer );
				$auth = $c->get( RestAuthenticator::class );
				assert( $auth instanceof RestAuthenticator );
				return new AssignmentsController( VL_LMS_API_NAMESPACE, $service, $repo, $transformer, $auth );
			}
		);

		$container->set(
			AdminAssignmentsController::class,
			static function ( Container $c ): AdminAssignmentsController {
				$service = $c->get( AssignmentSubmissionService::class );
				assert( $service instanceof AssignmentSubmissionService );
				$repo = $c->get( AssignmentSubmissionRepository::class );
				assert( $repo instanceof AssignmentSubmissionRepository );
				$transformer = $c->get( SubmissionTransformer::class );
				assert( $transformer instanceof SubmissionTransformer );
				return new AdminAssignmentsController( VL_LMS_API_NAMESPACE, $service, $repo, $transformer );
			}
		);

		$container->set(
			SubmissionDetailPage::class,
			static function ( Container $c ): SubmissionDetailPage {
				$service = $c->get( AssignmentSubmissionService::class );
				assert( $service instanceof AssignmentSubmissionService );
				$repo = $c->get( AssignmentSubmissionRepository::class );
				assert( $repo instanceof AssignmentSubmissionRepository );
				$hierarchy = $c->get( EntityHierarchy::class );
				assert( $hierarchy instanceof EntityHierarchy );
				return new SubmissionDetailPage( $service, $repo, $hierarchy );
			}
		);

		$container->set(
			GradingQueuePage::class,
			static function ( Container $c ): GradingQueuePage {
				$repo = $c->get( AssignmentSubmissionRepository::class );
				assert( $repo instanceof AssignmentSubmissionRepository );
				$hierarchy = $c->get( EntityHierarchy::class );
				assert( $hierarchy instanceof EntityHierarchy );
				$detail = $c->get( SubmissionDetailPage::class );
				assert( $detail instanceof SubmissionDetailPage );
				return new GradingQueuePage( $repo, $hierarchy, $detail );
			}
		);

		// --- Phase 9.5 — wp-admin Settings page (Zoom credentials) ---

		$container->set(
			ZoomSettingsSection::class,
			static function ( Container $c ): ZoomSettingsSection {
				$provider = $c->get( ZoomSettingsProvider::class );
				assert( $provider instanceof ZoomSettingsProvider );
				return new ZoomSettingsSection( $provider );
			}
		);

		$container->set(
			LiqPaySettingsSection::class,
			static function ( Container $c ): LiqPaySettingsSection {
				$settings = $c->get( LiqPaySettings::class );
				assert( $settings instanceof LiqPaySettings );
				return new LiqPaySettingsSection( $settings );
			}
		);

		$container->set(
			SettingsPage::class,
			static function ( Container $c ): SettingsPage {
				$zoom = $c->get( ZoomSettingsSection::class );
				assert( $zoom instanceof ZoomSettingsSection );
				$liqpay = $c->get( LiqPaySettingsSection::class );
				assert( $liqpay instanceof LiqPaySettingsSection );
				return new SettingsPage( $zoom, $liqpay );
			}
		);

		return $container;
	}
}
