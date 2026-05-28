<?php

declare(strict_types=1);

namespace VL\LMS\Admin\Students;

use VL\LMS\Domain\Enrollment\EnrollmentSource;
use VL\LMS\Domain\Enrollment\EnrollmentStatus;
use VL\LMS\Mail\CourseAccessGrantedMailer;
use VL\LMS\Repositories\EnrollmentRepository;
use VL\LMS\Services\Enrollment\EnrollmentService;
use WP_Post;
use WP_User;

/**
 * Form-submission orchestrator for the per-student detail page's "Курси"
 * tab. Owns two `admin_post_*` actions — manual course grant and revoke.
 *
 * Mirrors {@see \VL\LMS\Admin\Groups\GroupFormHandler}: each handler
 *   1. gates on capability + nonce,
 *   2. parses + sanitises input,
 *   3. delegates the state change to {@see EnrollmentService},
 *   4. (grant only) notifies the student via {@see CourseAccessGrantedMailer},
 *   5. redirects back to the detail page with a `notice=…` query arg.
 *
 * Mutations gate on `vl_manage_enrollments` (moderator + administrator),
 * deliberately stronger than the view-only `vl_view_all_enrollments` that
 * guards the list/detail screens. The course picker reuses the existing
 * {@see \VL\LMS\Admin\Groups\GroupFormHandler::AJAX_SEARCH_COURSES} AJAX
 * endpoint — no new search action is registered here.
 *
 * Not declared `final` — tests subclass to bypass `wp_die` / `wp_redirect`.
 *
 * @author Tymofii Synianskyi
 */
class StudentEnrollmentFormHandler {

	public const string ACTION_GRANT  = 'vl_lms_student_grant_course';
	public const string ACTION_REVOKE = 'vl_lms_student_revoke_course';
	public const string CAPABILITY    = 'vl_manage_enrollments';

	public function __construct(
		private readonly EnrollmentService $enrollments,
		private readonly EnrollmentRepository $repository,
		private readonly CourseAccessGrantedMailer $mailer
	) {
	}

	public function register(): void {
		add_action( 'admin_post_' . self::ACTION_GRANT, [ $this, 'handle_grant' ] );
		add_action( 'admin_post_' . self::ACTION_REVOKE, [ $this, 'handle_revoke' ] );
	}

	public function handle_grant(): void {
		$this->require_cap();

		$user_id = $this->post_int( 'user_id' );
		if ( null === $user_id ) {
			$this->redirect_to_list();
			return;
		}

		check_admin_referer( self::ACTION_GRANT . '_' . $user_id );

		if ( ! $this->user_is_student( $user_id ) ) {
			$this->redirect_to_detail( $user_id, 'courses', 'user_not_found' );
			return;
		}

		$course_id = $this->post_int( 'course_id' );
		if ( null === $course_id || ! $this->is_published_course( $course_id ) ) {
			$this->redirect_to_detail( $user_id, 'courses', 'course_not_found' );
			return;
		}

		$this->enrollments->enroll( $user_id, $course_id, EnrollmentSource::GRANT );
		$this->mailer->send( $user_id, $course_id );

		$this->redirect_to_detail( $user_id, 'courses', 'course_granted' );
	}

	public function handle_revoke(): void {
		$this->require_cap();

		$user_id   = $this->get_int( 'user_id' );
		$course_id = $this->get_int( 'course_id' );
		if ( null === $user_id || null === $course_id ) {
			$this->redirect_to_list();
			return;
		}

		check_admin_referer( self::ACTION_REVOKE . '_' . $user_id . '_' . $course_id );

		$enrollment = $this->repository->find_for_user_and_course( $user_id, $course_id );
		if ( null === $enrollment
			|| ( EnrollmentStatus::ACTIVE !== $enrollment->status && EnrollmentStatus::COMPLETED !== $enrollment->status )
		) {
			$this->redirect_to_detail( $user_id, 'courses', 'enrollment_not_found' );
			return;
		}

		$this->enrollments->revoke( $enrollment->id, get_current_user_id() );

		$this->redirect_to_detail( $user_id, 'courses', 'course_revoked' );
	}

	/**
	 * Indirected so unit tests can subclass and assert without exiting.
	 */
	protected function require_cap(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
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

	private function redirect_to_list(): void {
		$this->redirect( add_query_arg( [ 'page' => StudentsListPage::PAGE_SLUG ], admin_url( 'admin.php' ) ) );
	}

	private function redirect_to_detail( int $user_id, string $tab, string $notice ): void {
		$args = [
			'page'   => StudentsListPage::PAGE_SLUG,
			'action' => 'view',
			'id'     => $user_id,
			'tab'    => $tab,
		];
		if ( '' !== $notice ) {
			$args['notice'] = $notice;
		}
		$this->redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
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

	private function post_int( string $key ): ?int {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in caller via check_admin_referer().
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
}
