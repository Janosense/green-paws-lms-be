<?php

declare(strict_types=1);

namespace VL\LMS\Admin\Groups;

use VL\LMS\Domain\Group\AccessEntityType;
use VL\LMS\Domain\Group\GroupStatus;
use VL\LMS\Repositories\GroupMemberRepository;
use VL\LMS\Repositories\GroupRepository;
use VL\LMS\Services\Groups\GroupEnrollmentService;
use VL\LMS\Services\Groups\GroupService;
use VL\LMS\Slug\CyrillicTransliterator;
use WP_Post;
use WP_User;

/**
 * Form-submission orchestrator for the Groups admin UI.
 *
 * Owns six `admin_post_*` actions (create / update / member add /
 * member remove / course grant / course revoke) and two `wp_ajax_*`
 * actions (student-search / course-search) used by the detail-page
 * pickers. Each handler:
 *   1. capability + nonce gate,
 *   2. parses + sanitises input,
 *   3. delegates the state change to {@see GroupService},
 *   4. immediately calls the matching {@see GroupEnrollmentService}
 *      method so `vl_enrollments` rows stay coherent without inventing
 *      a new domain event,
 *   5. redirects back with a `notice=…` query arg the page reads.
 *
 * Not declared `final` — tests subclass to bypass `wp_die` / `wp_redirect`.
 *
 * @author Tymofii Synianskyi
 */
class GroupFormHandler {

	public const string ACTION_CREATE        = 'vl_lms_group_create';
	public const string ACTION_UPDATE        = 'vl_lms_group_update';
	public const string ACTION_MEMBER_ADD    = 'vl_lms_group_member_add';
	public const string ACTION_MEMBER_REMOVE = 'vl_lms_group_member_remove';
	public const string ACTION_COURSE_GRANT  = 'vl_lms_group_course_grant';
	public const string ACTION_COURSE_REVOKE = 'vl_lms_group_course_revoke';
	public const string AJAX_SEARCH_STUDENTS = 'vl_lms_search_students';
	public const string AJAX_SEARCH_COURSES  = 'vl_lms_search_courses';

	public function __construct(
		private readonly GroupService $groups,
		private readonly GroupEnrollmentService $fanout,
		private readonly GroupRepository $group_repository,
		private readonly GroupMemberRepository $member_repository,
		private readonly CyrillicTransliterator $transliterator
	) {
	}

	public function register(): void {
		add_action( 'admin_post_' . self::ACTION_CREATE, [ $this, 'handle_create' ] );
		add_action( 'admin_post_' . self::ACTION_UPDATE, [ $this, 'handle_update' ] );
		add_action( 'admin_post_' . self::ACTION_MEMBER_ADD, [ $this, 'handle_member_add' ] );
		add_action( 'admin_post_' . self::ACTION_MEMBER_REMOVE, [ $this, 'handle_member_remove' ] );
		add_action( 'admin_post_' . self::ACTION_COURSE_GRANT, [ $this, 'handle_course_grant' ] );
		add_action( 'admin_post_' . self::ACTION_COURSE_REVOKE, [ $this, 'handle_course_revoke' ] );
		add_action( 'wp_ajax_' . self::AJAX_SEARCH_STUDENTS, [ $this, 'handle_student_search' ] );
		add_action( 'wp_ajax_' . self::AJAX_SEARCH_COURSES, [ $this, 'handle_course_search' ] );
	}

	public function handle_create(): void {
		$this->require_cap();
		check_admin_referer( self::ACTION_CREATE );

		$name        = $this->post_string( 'name' );
		$description = $this->post_string( 'description' );
		$max_members = $this->post_int( 'max_members' );

		if ( '' === $name ) {
			$this->redirect_to_list( 'invalid_name' );
			return;
		}

		$slug = $this->transliterator->slug( $name );
		if ( '' === $slug ) {
			$slug = 'group-' . wp_generate_password( 6, false, false );
		}
		$slug = $this->uniquify_slug( $slug );

		try {
			$group = $this->groups->create_group(
				$name,
				$slug,
				get_current_user_id(),
				description: '' === $description ? null : $description,
				max_members: $max_members,
			);
		} catch ( \InvalidArgumentException ) {
			$this->redirect_to_list( 'invalid_name' );
			return;
		} catch ( \RuntimeException ) {
			$this->redirect_to_list( 'duplicate_slug' );
			return;
		}

		$this->redirect_to_detail( $group->id, 'group_created' );
	}

	public function handle_update(): void {
		$this->require_cap();

		$group_id = $this->post_int( 'group_id' );
		if ( null === $group_id ) {
			$this->redirect_to_list( '' );
			return;
		}

		check_admin_referer( self::ACTION_UPDATE . '_' . $group_id );

		$group = $this->group_repository->find_by_id( $group_id );
		if ( null === $group ) {
			$this->redirect_to_list( '' );
			return;
		}

		$name        = $this->post_string( 'name' );
		$description = $this->post_string( 'description' );
		$status_raw  = $this->post_string( 'status' );

		if ( '' === $name ) {
			$this->redirect_to_detail( $group_id, 'invalid_name' );
			return;
		}

		$status = GroupStatus::tryFrom( $status_raw ) ?? $group->status;

		$this->group_repository->update(
			$group_id,
			[
				'name'        => $name,
				'description' => '' === $description ? null : $description,
				'status'      => $status->value,
			]
		);

		$this->redirect_to_detail( $group_id, 'group_updated' );
	}

	public function handle_member_add(): void {
		$this->require_cap();

		$group_id = $this->post_int( 'group_id' );
		if ( null === $group_id ) {
			$this->redirect_to_list( '' );
			return;
		}

		check_admin_referer( self::ACTION_MEMBER_ADD . '_' . $group_id );

		$user_id = $this->post_int( 'user_id' );
		if ( null === $user_id || ! $this->user_is_student( $user_id ) ) {
			$this->redirect_to_detail( $group_id, 'user_not_found' );
			return;
		}

		try {
			$this->groups->add_member( $group_id, $user_id );
		} catch ( \RuntimeException ) {
			$this->redirect_to_detail( $group_id, 'capacity_full' );
			return;
		}

		$member = $this->member_repository->find_active( $group_id, $user_id );
		if ( null !== $member ) {
			$this->fanout->on_member_added( $member );
		}

		$this->redirect_to_detail( $group_id, 'member_added' );
	}

	public function handle_member_remove(): void {
		$this->require_cap();

		$group_id = $this->get_int( 'group_id' );
		$user_id  = $this->get_int( 'user_id' );
		if ( null === $group_id || null === $user_id ) {
			$this->redirect_to_list( '' );
			return;
		}

		check_admin_referer( self::ACTION_MEMBER_REMOVE . '_' . $group_id . '_' . $user_id );

		$this->groups->remove_member( $group_id, $user_id );
		$this->fanout->on_member_removed( $group_id, $user_id );

		$this->redirect_to_detail( $group_id, 'member_removed' );
	}

	public function handle_course_grant(): void {
		$this->require_cap();

		$group_id = $this->post_int( 'group_id' );
		if ( null === $group_id ) {
			$this->redirect_to_list( '' );
			return;
		}

		check_admin_referer( self::ACTION_COURSE_GRANT . '_' . $group_id );

		$course_id = $this->post_int( 'course_id' );
		if ( null === $course_id || ! $this->is_published_course( $course_id ) ) {
			$this->redirect_to_detail( $group_id, 'course_not_found' );
			return;
		}

		$access = $this->groups->grant_access(
			$group_id,
			AccessEntityType::COURSE,
			$course_id,
			get_current_user_id()
		);
		$this->fanout->on_access_granted( $access );

		$this->redirect_to_detail( $group_id, 'course_granted' );
	}

	public function handle_course_revoke(): void {
		$this->require_cap();

		$group_id  = $this->get_int( 'group_id' );
		$course_id = $this->get_int( 'course_id' );
		if ( null === $group_id || null === $course_id ) {
			$this->redirect_to_list( '' );
			return;
		}

		check_admin_referer( self::ACTION_COURSE_REVOKE . '_' . $group_id . '_' . $course_id );

		$this->groups->revoke_access( $group_id, AccessEntityType::COURSE, $course_id );
		$this->fanout->on_access_revoked( $group_id, AccessEntityType::COURSE, $course_id );

		$this->redirect_to_detail( $group_id, 'course_revoked' );
	}

	public function handle_student_search(): void {
		if ( ! current_user_can( GroupsListPage::CAPABILITY ) ) {
			wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
		}
		check_ajax_referer( self::AJAX_SEARCH_STUDENTS, 'nonce' );

		$query = $this->get_string( 'q' );
		if ( '' === $query ) {
			wp_send_json_success( [] );
		}

		$users = get_users(
			[
				'role'    => 'student',
				'search'  => '*' . $query . '*',
				'fields'  => [ 'ID', 'display_name', 'user_email' ],
				'number'  => 10,
				'orderby' => 'display_name',
				'order'   => 'ASC',
			]
		);

		$payload = [];
		foreach ( $users as $user ) {
			if ( ! is_object( $user ) ) {
				continue;
			}
			$payload[] = [
				'id'    => (int) $user->ID,
				'name'  => isset( $user->display_name ) ? (string) $user->display_name : '',
				'email' => isset( $user->user_email ) ? (string) $user->user_email : '',
			];
		}

		wp_send_json_success( $payload );
	}

	public function handle_course_search(): void {
		if ( ! current_user_can( GroupsListPage::CAPABILITY ) ) {
			wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
		}
		check_ajax_referer( self::AJAX_SEARCH_COURSES, 'nonce' );

		$query = $this->get_string( 'q' );
		if ( '' === $query ) {
			wp_send_json_success( [] );
		}

		$posts = get_posts(
			[
				'post_type'        => 'vl_course',
				'post_status'      => 'publish',
				's'                => $query,
				'posts_per_page'   => 10,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'suppress_filters' => false,
			]
		);

		$payload = [];
		foreach ( $posts as $post ) {
			if ( ! $post instanceof WP_Post ) {
				continue;
			}
			$payload[] = [
				'id'    => (int) $post->ID,
				'title' => (string) $post->post_title,
			];
		}

		wp_send_json_success( $payload );
	}

	/**
	 * Indirected so unit tests can subclass and assert without exiting.
	 */
	protected function require_cap(): void {
		if ( ! current_user_can( GroupsListPage::CAPABILITY ) ) {
			wp_die( esc_html__( 'Доступ заборонено.', 'vl-lms' ), '', [ 'response' => 403 ] );
		}
	}

	/**
	 * Indirected so unit tests can subclass and capture target URLs.
	 */
	protected function redirect( string $url ): void {
		wp_safe_redirect( $url );
		exit;
	}

	private function redirect_to_list( string $notice ): void {
		$args = [ 'page' => GroupsListPage::PAGE_SLUG ];
		if ( '' !== $notice ) {
			$args['notice'] = $notice;
		}
		$this->redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
	}

	private function redirect_to_detail( int $group_id, string $notice ): void {
		$args = [
			'page'   => GroupsListPage::PAGE_SLUG,
			'action' => 'edit',
			'id'     => $group_id,
		];
		if ( '' !== $notice ) {
			$args['notice'] = $notice;
		}
		$this->redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
	}

	private function uniquify_slug( string $base ): string {
		$slug    = $base;
		$attempt = 1;
		while ( null !== $this->group_repository->find_by_slug( $slug ) ) {
			++$attempt;
			$slug = $base . '-' . $attempt;
		}
		return $slug;
	}

	private function user_is_student( int $user_id ): bool {
		$user = get_userdata( $user_id );
		if ( ! $user instanceof WP_User ) {
			return false;
		}
		return in_array( 'student', (array) $user->roles, true );
	}

	private function is_published_course( int $course_id ): bool {
		$post = get_post( $course_id );
		if ( ! $post instanceof WP_Post ) {
			return false;
		}
		return 'vl_course' === $post->post_type && 'publish' === $post->post_status;
	}

	private function post_string( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in caller via check_admin_referer().
		if ( ! isset( $_POST[ $key ] ) ) {
			return '';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$raw = wp_unslash( (string) $_POST[ $key ] );
		return trim( sanitize_text_field( $raw ) );
	}

	private function post_int( string $key ): ?int {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! isset( $_POST[ $key ] ) ) {
			return null;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$raw = wp_unslash( (string) $_POST[ $key ] );
		if ( '' === $raw || ! is_numeric( $raw ) ) {
			return null;
		}
		$value = (int) $raw;
		return $value > 0 ? $value : null;
	}

	private function get_int( string $key ): ?int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified in caller via check_admin_referer().
		if ( ! isset( $_GET[ $key ] ) ) {
			return null;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$raw = wp_unslash( (string) $_GET[ $key ] );
		if ( '' === $raw || ! is_numeric( $raw ) ) {
			return null;
		}
		$value = (int) $raw;
		return $value > 0 ? $value : null;
	}

	private function get_string( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified via check_ajax_referer().
		if ( ! isset( $_GET[ $key ] ) ) {
			return '';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$raw = wp_unslash( (string) $_GET[ $key ] );
		return trim( sanitize_text_field( $raw ) );
	}
}
