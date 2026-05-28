<?php

declare(strict_types=1);

namespace VL\LMS\Admin\Students;

use VL\LMS\Repositories\EnrollmentRepository;
use VL\LMS\Repositories\GroupMemberRepository;
use VL\LMS\Repositories\GroupRepository;

/**
 * Admin "Студенти" list page-controller (`?page=vl-lms-students`).
 *
 * Read-only view of every WP user with `role=student`, joined with
 * LMS-side group memberships and completed-course counts. Decoupled
 * from the generic wp-admin Users screen so admins see only the
 * student-relevant columns.
 *
 * Not declared `final` — unit tests subclass to bypass `wp_die`.
 *
 * @author Tymofii Synianskyi
 */
class StudentsListPage {

	public const string PAGE_SLUG  = 'vl-lms-students';
	public const string CAPABILITY = 'vl_view_all_enrollments';

	public function __construct(
		private readonly GroupRepository $groups,
		private readonly GroupMemberRepository $members,
		private readonly EnrollmentRepository $enrollments,
		private readonly StudentDetailPage $detail_page
	) {
	}

	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			$this->forbidden();
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing param.
		$action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['action'] ) ) : '';
		if ( 'view' === $action ) {
			$this->detail_page->render();
			return;
		}

		$table = $this->build_table();
		$table->prepare_items();

		echo '<div class="wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Студенти', 'vl-lms' ) . '</h1>';
		echo '<hr class="wp-header-end" />';
		echo '<p class="description">' . esc_html__( 'Усі користувачі з роллю «Студент». Перегляньте групи та кількість завершених курсів за кожним студентом.', 'vl-lms' ) . '</p>';

		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE_SLUG ) . '" />';
		$table->search_box( __( 'Пошук', 'vl-lms' ), 'student-search' );
		$table->display();
		echo '</form>';

		echo '</div>';
	}

	/**
	 * Indirected so unit tests can subclass and inject a fake table.
	 */
	protected function build_table(): StudentsListTable {
		return new StudentsListTable( $this->groups, $this->members, $this->enrollments );
	}

	/**
	 * Indirected so unit tests can subclass and bypass `wp_die`.
	 */
	protected function forbidden(): void {
		wp_die( esc_html__( 'Доступ заборонено.', 'vl-lms' ), '', [ 'response' => 403 ] );
	}
}
