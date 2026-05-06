<?php

declare(strict_types=1);

namespace VL\LMS\Admin\Assignments;

use VL\LMS\Learn\EntityHierarchy;
use VL\LMS\Repositories\AssignmentSubmissionRepository;

/**
 * Phase 9.4 — wp-admin "Перевірка завдань" page-controller.
 *
 * Routes between {@see GradingQueueTable} (default list view) and
 * {@see SubmissionDetailPage} (when `?action=detail&id=…` is present).
 *
 * Not declared `final` — tests subclass to bypass `WP_List_Table` boot.
 *
 * @author Tymofii Synianskyi
 */
class GradingQueuePage {

	public const string PAGE_SLUG = 'vl-lms-grading-queue';

	public function __construct(
		private readonly AssignmentSubmissionRepository $repository,
		private readonly EntityHierarchy $entity_hierarchy,
		private readonly SubmissionDetailPage $detail_page
	) {
	}

	public function render(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation params.
		$action = isset( $_GET['action'] ) ? sanitize_key( (string) wp_unslash( $_GET['action'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation params.
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;

		if ( 'detail' === $action && $id > 0 ) {
			$this->detail_page->render( $id );
			return;
		}

		$this->render_list();
	}

	private function render_list(): void {
		echo '<div class="wrap">';
		echo '<h1>Перевірка завдань</h1>';

		$this->render_notice();

		$table = new GradingQueueTable( $this->repository, $this->entity_hierarchy );
		$table->prepare_items();

		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE_SLUG ) . '" />';
		$table->display();
		echo '</form>';
		echo '</div>';
	}

	private function render_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- one-shot UI notice.
		$notice = isset( $_GET[ SubmissionDetailPage::NOTICE_QUERY_ARG ] )
			? sanitize_key( (string) wp_unslash( $_GET[ SubmissionDetailPage::NOTICE_QUERY_ARG ] ) )
			: '';

		$message = match ( $notice ) {
			SubmissionDetailPage::NOTICE_GRADED        => [ 'success', 'Оцінено успішно.' ],
			SubmissionDetailPage::NOTICE_REJECTED      => [ 'success', 'Надсилання відхилено.' ],
			SubmissionDetailPage::NOTICE_INVALID_SCORE => [ 'error', 'Невірний бал або відсутній коментар.' ],
			SubmissionDetailPage::NOTICE_NOT_FOUND     => [ 'error', 'Надсилання не знайдено.' ],
			default                                    => null,
		};

		if ( null === $message ) {
			return;
		}

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $message[0] ),
			esc_html( $message[1] )
		);
	}
}
