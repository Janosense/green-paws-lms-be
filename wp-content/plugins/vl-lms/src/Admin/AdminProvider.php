<?php

declare(strict_types=1);

namespace VL\LMS\Admin;

use VL\LMS\Admin\Assignments\AssignmentPickerAjaxHandler;
use VL\LMS\Admin\Groups\GroupFormHandler;
use VL\LMS\Admin\Groups\GroupsListPage;
use VL\LMS\Admin\Students\StudentsListPage;
use VL\LMS\Admin\Lessons\LessonPickerAjaxHandler;
use VL\LMS\Admin\Menu\AdminMenuProvider;
use VL\LMS\Admin\MetaBoxes\AbstractMetaBox;
use VL\LMS\Admin\MetaBoxes\ChildList\AbstractChildListMetaBox;
use VL\LMS\Admin\Modules\ModulePickerAjaxHandler;
use VL\LMS\Admin\Questions\QuestionPickerAjaxHandler;
use VL\LMS\Admin\Quizzes\QuizPickerAjaxHandler;
use VL\LMS\Admin\Reorder\ReorderAjaxHandler;
use VL\LMS\Admin\Topics\TopicPickerAjaxHandler;
use WP_Post;
use WP_Screen;
use WP_User;

/**
 * Wires the typed CPT meta-box subsystem into wp-admin.
 *
 * Responsibilities:
 *  - Registers all VL LMS meta-boxes on `add_meta_boxes`.
 *  - Routes `save_post` writes through each meta-box's `save()` method.
 *  - Removes the default WordPress "Custom Fields" meta-box from every
 *    LMS CPT screen so authors are not faced with the raw key/value
 *    table, and core's `authordiv` from the course and webinar screens
 *    in favour of the typed `AuthorMetaBox`.
 *  - Enqueues `admin-meta-boxes.{js,css}` only on the LMS CPT post-edit
 *    screens.
 *  - Backs the `wp_ajax_vl_lms_search_instructors` action used by the
 *    co-instructor picker on the Course meta-box.
 *
 * Not declared `final` — the unit tests construct subclasses to assert
 * hook registration with Mockery.
 *
 * @author Tymofii Synianskyi
 */
class AdminProvider {

	private const string SCRIPT_HANDLE        = 'vl-lms-admin-meta-boxes';
	private const string STYLE_HANDLE         = 'vl-lms-admin-meta-boxes';
	private const string GROUPS_SCRIPT_HANDLE = 'vl-lms-admin-groups';
	private const string GROUPS_STYLE_HANDLE  = 'vl-lms-admin-groups';
	private const string AJAX_ACTION          = 'vl_lms_search_instructors';
	private const string AJAX_NONCE           = 'vl_lms_search_instructors_nonce';

	/** @var list<string> */
	private const array CPT_SLUGS = [
		'vl_course',
		'vl_module',
		'vl_lesson',
		'vl_topic',
		'vl_session',
		'vl_webinar',
		'vl_quiz',
		'vl_quiz_question',
		'vl_assignment',
	];

	/** @var list<AbstractMetaBox> */
	private array $meta_boxes;

	/** @var list<AbstractChildListMetaBox> */
	private array $child_list_boxes;

	private ReorderAjaxHandler $reorder_handler;

	private ModulePickerAjaxHandler $module_picker;

	private LessonPickerAjaxHandler $lesson_picker;

	private TopicPickerAjaxHandler $topic_picker;

	private QuestionPickerAjaxHandler $question_picker;

	private QuizPickerAjaxHandler $quiz_picker;

	private AssignmentPickerAjaxHandler $assignment_picker;

	private ?AdminMenuProvider $menu_provider;

	/**
	 * @param list<AbstractMetaBox>          $meta_boxes
	 * @param list<AbstractChildListMetaBox> $child_list_boxes
	 */
	public function __construct(
		array $meta_boxes,
		array $child_list_boxes = [],
		?ReorderAjaxHandler $reorder_handler = null,
		?AdminMenuProvider $menu_provider = null,
		?ModulePickerAjaxHandler $module_picker = null,
		?LessonPickerAjaxHandler $lesson_picker = null,
		?TopicPickerAjaxHandler $topic_picker = null,
		?QuestionPickerAjaxHandler $question_picker = null,
		?QuizPickerAjaxHandler $quiz_picker = null,
		?AssignmentPickerAjaxHandler $assignment_picker = null
	) {
		$this->meta_boxes        = $meta_boxes;
		$this->child_list_boxes  = $child_list_boxes;
		$this->reorder_handler   = $reorder_handler ?? new ReorderAjaxHandler();
		$this->module_picker     = $module_picker ?? new ModulePickerAjaxHandler();
		$this->lesson_picker     = $lesson_picker ?? new LessonPickerAjaxHandler();
		$this->topic_picker      = $topic_picker ?? new TopicPickerAjaxHandler();
		$this->question_picker   = $question_picker ?? new QuestionPickerAjaxHandler();
		$this->quiz_picker       = $quiz_picker ?? new QuizPickerAjaxHandler();
		$this->assignment_picker = $assignment_picker ?? new AssignmentPickerAjaxHandler();
		$this->menu_provider     = $menu_provider;
	}

	public function boot(): void {
		add_action( 'add_meta_boxes', [ $this, 'register_meta_boxes' ] );
		add_action( 'save_post', [ $this, 'on_save_post' ], 10, 2 );
		add_action( 'admin_init', [ $this, 'remove_default_meta_boxes' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, [ $this, 'handle_instructor_search' ] );
		add_action( 'wp_ajax_vl_lms_reorder', [ $this->reorder_handler, 'handle' ] );
		add_action( 'wp_ajax_' . ModulePickerAjaxHandler::SEARCH_ACTION, [ $this->module_picker, 'search' ] );
		add_action( 'wp_ajax_' . ModulePickerAjaxHandler::ATTACH_ACTION, [ $this->module_picker, 'attach' ] );
		add_action( 'wp_ajax_' . ModulePickerAjaxHandler::DETACH_ACTION, [ $this->module_picker, 'detach' ] );
		add_action( 'wp_ajax_' . LessonPickerAjaxHandler::SEARCH_ACTION, [ $this->lesson_picker, 'search' ] );
		add_action( 'wp_ajax_' . LessonPickerAjaxHandler::ATTACH_ACTION, [ $this->lesson_picker, 'attach' ] );
		add_action( 'wp_ajax_' . LessonPickerAjaxHandler::DETACH_ACTION, [ $this->lesson_picker, 'detach' ] );
		add_action( 'wp_ajax_' . TopicPickerAjaxHandler::SEARCH_ACTION, [ $this->topic_picker, 'search' ] );
		add_action( 'wp_ajax_' . TopicPickerAjaxHandler::ATTACH_ACTION, [ $this->topic_picker, 'attach' ] );
		add_action( 'wp_ajax_' . TopicPickerAjaxHandler::DETACH_ACTION, [ $this->topic_picker, 'detach' ] );
		add_action( 'wp_ajax_' . QuestionPickerAjaxHandler::SEARCH_ACTION, [ $this->question_picker, 'search' ] );
		add_action( 'wp_ajax_' . QuestionPickerAjaxHandler::ATTACH_ACTION, [ $this->question_picker, 'attach' ] );
		add_action( 'wp_ajax_' . QuestionPickerAjaxHandler::DETACH_ACTION, [ $this->question_picker, 'detach' ] );
		add_action( 'wp_ajax_' . QuizPickerAjaxHandler::SEARCH_ACTION, [ $this->quiz_picker, 'search' ] );
		add_action( 'wp_ajax_' . QuizPickerAjaxHandler::ATTACH_ACTION, [ $this->quiz_picker, 'attach' ] );
		add_action( 'wp_ajax_' . QuizPickerAjaxHandler::DETACH_ACTION, [ $this->quiz_picker, 'detach' ] );
		add_action( 'wp_ajax_' . AssignmentPickerAjaxHandler::SEARCH_ACTION, [ $this->assignment_picker, 'search' ] );
		add_action( 'wp_ajax_' . AssignmentPickerAjaxHandler::ATTACH_ACTION, [ $this->assignment_picker, 'attach' ] );
		add_action( 'wp_ajax_' . AssignmentPickerAjaxHandler::DETACH_ACTION, [ $this->assignment_picker, 'detach' ] );
		if ( null !== $this->menu_provider ) {
			add_action( 'admin_menu', [ $this->menu_provider, 'register' ], 20 );
		}
	}

	public function register_meta_boxes( string $post_type ): void {
		foreach ( $this->meta_boxes as $box ) {
			if ( $box->post_type() !== $post_type ) {
				continue;
			}
			$box->register();
		}
		foreach ( $this->child_list_boxes as $list_box ) {
			if ( $list_box->post_type() !== $post_type ) {
				continue;
			}
			$list_box->register();
		}
	}

	public function on_save_post( int $post_id, WP_Post $post ): void {
		foreach ( $this->meta_boxes as $box ) {
			if ( $box->post_type() !== $post->post_type ) {
				continue;
			}
			$box->save( $post_id, $post );
		}
	}

	public function remove_default_meta_boxes(): void {
		foreach ( self::CPT_SLUGS as $cpt ) {
			remove_meta_box( 'postcustom', $cpt, 'normal' );
		}

		// Core's author box would duplicate `AuthorMetaBox` — both post
		// `post_author_override`, and with two selects sharing one name
		// the last in the DOM silently wins.
		remove_meta_box( 'authordiv', 'vl_course', 'normal' );
		remove_meta_box( 'authordiv', 'vl_webinar', 'normal' );
	}

	public function enqueue_assets( string $hook ): void {
		if ( $this->is_groups_admin_hook( $hook ) || $this->is_students_detail_hook( $hook ) ) {
			$this->enqueue_groups_assets();
			return;
		}

		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen instanceof WP_Screen ) {
			return;
		}
		if ( ! in_array( $screen->post_type, self::CPT_SLUGS, true ) ) {
			return;
		}

		wp_enqueue_media();

		$base_url = plugins_url( 'src/Admin/assets/', VL_LMS_FILE );

		wp_enqueue_style(
			self::STYLE_HANDLE,
			$base_url . 'admin-meta-boxes.css',
			[],
			VL_LMS_VERSION
		);

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			$base_url . 'admin-meta-boxes.js',
			[ 'jquery', 'jquery-ui-sortable', 'wp-i18n' ],
			VL_LMS_VERSION,
			true
		);

		wp_localize_script(
			self::SCRIPT_HANDLE,
			'VL_LMS_ADMIN',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'action'  => self::AJAX_ACTION,
				'nonce'   => wp_create_nonce( self::AJAX_NONCE ),
				'modules' => [
					'actions' => [
						'search' => ModulePickerAjaxHandler::SEARCH_ACTION,
						'attach' => ModulePickerAjaxHandler::ATTACH_ACTION,
						'detach' => ModulePickerAjaxHandler::DETACH_ACTION,
					],
					'nonces'  => [
						'search' => wp_create_nonce( ModulePickerAjaxHandler::SEARCH_ACTION ),
						'attach' => wp_create_nonce( ModulePickerAjaxHandler::ATTACH_ACTION ),
						'detach' => wp_create_nonce( ModulePickerAjaxHandler::DETACH_ACTION ),
					],
					'i18n'    => [
						'noModules'     => __( 'Модулів не знайдено', 'vl-lms' ),
						'confirmUnlink' => __( 'Відкріпити модуль від цього курсу?', 'vl-lms' ),
						'edit'          => __( 'Редагувати', 'vl-lms' ),
						'unlink'        => __( 'Відкріпити', 'vl-lms' ),
					],
				],
				// Entity-keyed config for the generic add / unlink picker
				// (lesson + topic lists). Looked up by the `data-entity`
				// attribute the JS reads off each picker.
				'pickers' => [
					'lesson'   => [
						'actions' => [
							'search' => LessonPickerAjaxHandler::SEARCH_ACTION,
							'attach' => LessonPickerAjaxHandler::ATTACH_ACTION,
							'detach' => LessonPickerAjaxHandler::DETACH_ACTION,
						],
						'nonces'  => [
							'search' => wp_create_nonce( LessonPickerAjaxHandler::SEARCH_ACTION ),
							'attach' => wp_create_nonce( LessonPickerAjaxHandler::ATTACH_ACTION ),
							'detach' => wp_create_nonce( LessonPickerAjaxHandler::DETACH_ACTION ),
						],
						'i18n'    => [
							'confirmUnlink' => __( 'Відкріпити цей урок?', 'vl-lms' ),
							'edit'          => __( 'Редагувати', 'vl-lms' ),
							'unlink'        => __( 'Відкріпити', 'vl-lms' ),
						],
					],
					'topic'    => [
						'actions' => [
							'search' => TopicPickerAjaxHandler::SEARCH_ACTION,
							'attach' => TopicPickerAjaxHandler::ATTACH_ACTION,
							'detach' => TopicPickerAjaxHandler::DETACH_ACTION,
						],
						'nonces'  => [
							'search' => wp_create_nonce( TopicPickerAjaxHandler::SEARCH_ACTION ),
							'attach' => wp_create_nonce( TopicPickerAjaxHandler::ATTACH_ACTION ),
							'detach' => wp_create_nonce( TopicPickerAjaxHandler::DETACH_ACTION ),
						],
						'i18n'    => [
							'confirmUnlink' => __( 'Відкріпити цю тему?', 'vl-lms' ),
							'edit'          => __( 'Редагувати', 'vl-lms' ),
							'unlink'        => __( 'Відкріпити', 'vl-lms' ),
						],
					],
					'question' => [
						'actions' => [
							'search' => QuestionPickerAjaxHandler::SEARCH_ACTION,
							'attach' => QuestionPickerAjaxHandler::ATTACH_ACTION,
							'detach' => QuestionPickerAjaxHandler::DETACH_ACTION,
						],
						'nonces'  => [
							'search' => wp_create_nonce( QuestionPickerAjaxHandler::SEARCH_ACTION ),
							'attach' => wp_create_nonce( QuestionPickerAjaxHandler::ATTACH_ACTION ),
							'detach' => wp_create_nonce( QuestionPickerAjaxHandler::DETACH_ACTION ),
						],
						'i18n'    => [
							'confirmUnlink' => __( 'Відкріпити це питання?', 'vl-lms' ),
							'edit'          => __( 'Редагувати', 'vl-lms' ),
							'unlink'        => __( 'Відкріпити', 'vl-lms' ),
						],
					],
					'quiz'       => [
						'actions' => [
							'search' => QuizPickerAjaxHandler::SEARCH_ACTION,
							'attach' => QuizPickerAjaxHandler::ATTACH_ACTION,
							'detach' => QuizPickerAjaxHandler::DETACH_ACTION,
						],
						'nonces'  => [
							'search' => wp_create_nonce( QuizPickerAjaxHandler::SEARCH_ACTION ),
							'attach' => wp_create_nonce( QuizPickerAjaxHandler::ATTACH_ACTION ),
							'detach' => wp_create_nonce( QuizPickerAjaxHandler::DETACH_ACTION ),
						],
						'i18n'    => [
							'confirmUnlink' => __( 'Відкріпити цей тест?', 'vl-lms' ),
							'edit'          => __( 'Редагувати', 'vl-lms' ),
							'unlink'        => __( 'Відкріпити', 'vl-lms' ),
						],
					],
					'assignment' => [
						'actions' => [
							'search' => AssignmentPickerAjaxHandler::SEARCH_ACTION,
							'attach' => AssignmentPickerAjaxHandler::ATTACH_ACTION,
							'detach' => AssignmentPickerAjaxHandler::DETACH_ACTION,
						],
						'nonces'  => [
							'search' => wp_create_nonce( AssignmentPickerAjaxHandler::SEARCH_ACTION ),
							'attach' => wp_create_nonce( AssignmentPickerAjaxHandler::ATTACH_ACTION ),
							'detach' => wp_create_nonce( AssignmentPickerAjaxHandler::DETACH_ACTION ),
						],
						'i18n'    => [
							'confirmUnlink' => __( 'Відкріпити це завдання?', 'vl-lms' ),
							'edit'          => __( 'Редагувати', 'vl-lms' ),
							'unlink'        => __( 'Відкріпити', 'vl-lms' ),
						],
					],
				],
			]
		);
	}

	/**
	 * Groups-page hook detection. wp-admin sets the hook on a parent-slug
	 * subpage to `{sanitized-parent-title}_page_{slug}` — in practice
	 * `toplevel_page_vl-lms` (parent) / `green-paws-lms_page_vl-lms-groups`
	 * (subpage). We only care about the suffix.
	 */
	private function is_groups_admin_hook( string $hook ): bool {
		return str_ends_with( $hook, '_page_' . GroupsListPage::PAGE_SLUG );
	}

	/**
	 * The per-student detail screen reuses the Groups course-search picker
	 * for its grant-access form, so load the same assets there — but only on
	 * the detail view (`action=view`), not the list, to keep them off the
	 * read-only table.
	 */
	private function is_students_detail_hook( string $hook ): bool {
		if ( ! str_ends_with( $hook, '_page_' . StudentsListPage::PAGE_SLUG ) ) {
			return false;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing param for asset gating.
		$action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['action'] ) ) : '';
		return 'view' === $action;
	}

	private function enqueue_groups_assets(): void {
		$base_url = plugins_url( 'src/Admin/assets/', VL_LMS_FILE );

		wp_enqueue_style(
			self::GROUPS_STYLE_HANDLE,
			$base_url . 'admin-groups.css',
			[],
			VL_LMS_VERSION
		);

		wp_enqueue_script(
			self::GROUPS_SCRIPT_HANDLE,
			$base_url . 'admin-groups.js',
			[ 'jquery' ],
			VL_LMS_VERSION,
			true
		);

		wp_localize_script(
			self::GROUPS_SCRIPT_HANDLE,
			'VL_LMS_GROUPS',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'actions' => [
					'students' => GroupFormHandler::AJAX_SEARCH_STUDENTS,
					'courses'  => GroupFormHandler::AJAX_SEARCH_COURSES,
				],
				'nonces'  => [
					'students' => wp_create_nonce( GroupFormHandler::AJAX_SEARCH_STUDENTS ),
					'courses'  => wp_create_nonce( GroupFormHandler::AJAX_SEARCH_COURSES ),
				],
				'i18n'    => [
					'noStudents' => __( 'Студентів не знайдено', 'vl-lms' ),
					'noCourses'  => __( 'Курсів не знайдено', 'vl-lms' ),
				],
			]
		);
	}

	public function handle_instructor_search(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce checked via check_ajax_referer below.
		$nonce = isset( $_REQUEST['nonce'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::AJAX_NONCE ) ) {
			wp_send_json_error( [ 'message' => 'invalid_nonce' ], 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified above.
		$query = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['q'] ) ) : '';
		$query = trim( $query );
		if ( '' === $query ) {
			wp_send_json_success( [] );
		}

		$users = get_users(
			[
				'role'    => 'instructor',
				'search'  => '*' . $query . '*',
				'fields'  => [ 'ID', 'display_name', 'user_email' ],
				'number'  => 10,
				'orderby' => 'display_name',
				'order'   => 'ASC',
			]
		);

		$payload = [];
		foreach ( $users as $user ) {
			if ( ! $user instanceof WP_User && ! is_object( $user ) ) {
				continue;
			}
			$payload[] = [
				'id'    => (int) $user->ID,
				'name'  => (string) $user->display_name,
				'email' => isset( $user->user_email ) ? (string) $user->user_email : '',
			];
		}

		wp_send_json_success( $payload );
	}
}
