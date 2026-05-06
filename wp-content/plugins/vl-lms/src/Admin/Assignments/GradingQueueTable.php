<?php

declare(strict_types=1);

namespace VL\LMS\Admin\Assignments;

use VL\LMS\Domain\Assignment\Submission;
use VL\LMS\Learn\EntityHierarchy;
use VL\LMS\Repositories\AssignmentSubmissionRepository;
use WP_Post;

if ( ! class_exists( '\WP_List_Table' ) && file_exists( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Phase 9.4 — pending-submissions list for the wp-admin grading queue.
 *
 * Wraps {@see AssignmentSubmissionRepository::list_pending()} into a
 * `WP_List_Table`. No bulk actions, no filters — the queue is always the
 * pending tail. The detail-view link points at the same admin page with
 * `?action=detail&id={id}` so {@see GradingQueuePage} can route in-place
 * without a separate sub-page slug.
 *
 * Not declared `final` — the unit test subclasses to swap repo wiring.
 *
 * @author Tymofii Synianskyi
 */
class GradingQueueTable extends \WP_List_Table {

	private const int PER_PAGE = 20;

	public function __construct(
		private readonly AssignmentSubmissionRepository $repository,
		private readonly EntityHierarchy $entity_hierarchy
	) {
		parent::__construct(
			[
				'singular' => 'submission',
				'plural'   => 'submissions',
				'ajax'     => false,
			]
		);
	}

	/**
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return [
			'student'    => 'Студент',
			'assignment' => 'Завдання',
			'course'     => 'Курс',
			'submitted'  => 'Подано',
			'actions'    => 'Дії',
		];
	}

	/**
	 * @return array<string, string>
	 */
	public function get_bulk_actions(): array {
		return [];
	}

	public function prepare_items(): void {
		$this->_column_headers = [
			$this->get_columns(),
			[],
			[],
		];

		$page = $this->current_page();

		$this->items = $this->repository->list_pending( $page, self::PER_PAGE );
		$total       = $this->repository->count_pending();

		$this->set_pagination_args(
			[
				'total_items' => $total,
				'per_page'    => self::PER_PAGE,
				'total_pages' => max( 1, (int) ceil( $total / self::PER_PAGE ) ),
			]
		);
	}

	/**
	 * @param Submission $item
	 * @param string     $column_name
	 */
	public function column_default( $item, $column_name ): string {
		unset( $item, $column_name );
		return '';
	}

	public function column_student( Submission $item ): string {
		$user = get_userdata( $item->user_id );
		if ( ! $user instanceof \WP_User ) {
			return '<em>(видалено)</em>';
		}
		$href = add_query_arg(
			[
				'user_id' => $item->user_id,
			],
			admin_url( 'user-edit.php' )
		);
		return sprintf(
			'<a href="%s">%s</a>',
			esc_url( $href ),
			esc_html( $user->user_login )
		);
	}

	public function column_assignment( Submission $item ): string {
		$post = get_post( $item->assignment_id );
		if ( ! $post instanceof WP_Post ) {
			return '<em>(видалено)</em>';
		}
		$edit_link = get_edit_post_link( $post->ID );
		$title     = (string) $post->post_title;
		if ( null === $edit_link ) {
			return esc_html( $title );
		}
		return sprintf(
			'<a href="%s">%s</a>',
			esc_url( $edit_link ),
			esc_html( $title )
		);
	}

	public function column_course( Submission $item ): string {
		$post = get_post( $item->assignment_id );
		if ( ! $post instanceof WP_Post ) {
			return '—';
		}
		$course = $this->entity_hierarchy->resolveCourse( $post );
		if ( ! $course instanceof WP_Post ) {
			return '—';
		}
		$edit_link = get_edit_post_link( $course->ID );
		$title     = (string) $course->post_title;
		if ( null === $edit_link ) {
			return esc_html( $title );
		}
		return sprintf(
			'<a href="%s">%s</a>',
			esc_url( $edit_link ),
			esc_html( $title )
		);
	}

	public function column_submitted( Submission $item ): string {
		return esc_html( mysql2date( 'd.m.Y H:i', $item->submitted_at ) );
	}

	protected function current_page(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination param.
		$raw = isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1;
		return max( 1, $raw );
	}

	public function column_actions( Submission $item ): string {
		$href = add_query_arg(
			[
				'page'   => GradingQueuePage::PAGE_SLUG,
				'action' => 'detail',
				'id'     => $item->id,
			],
			admin_url( 'admin.php' )
		);
		return sprintf(
			'<a class="button" href="%s">Перевірити</a>',
			esc_url( $href )
		);
	}
}
