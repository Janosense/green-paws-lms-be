<?php

declare(strict_types=1);

namespace VL\LMS\Admin\Assignments;

use VL\LMS\Domain\Assignment\Submission;
use VL\LMS\Domain\Assignment\SubmissionStatus;
use VL\LMS\Learn\EntityHierarchy;
use VL\LMS\Repositories\AssignmentSubmissionRepository;
use VL\LMS\Services\Assignments\AssignmentSubmissionService;
use VL\LMS\Services\Assignments\Exception\InvalidScoreException;
use VL\LMS\Services\Assignments\Exception\SubmissionNotFoundException;
use WP_Post;

/**
 * Phase 9.4 — detail / grade view for a single submission.
 *
 * Rendered by {@see GradingQueuePage} when `?action=detail&id={id}` is on
 * the URL. Form posts to `admin-post.php` with the
 * `vl_lms_grade_submission` / `vl_lms_reject_submission` actions; the
 * handlers below verify the nonce + cap, dispatch to the service, then
 * redirect back to the queue list with a notice query-arg.
 *
 * Not declared `final` — tests can subclass to bypass `wp_redirect`.
 *
 * @author Tymofii Synianskyi
 */
class SubmissionDetailPage {

	public const string GRADE_NONCE          = 'vl_lms_grade_submission';
	public const string REJECT_NONCE         = 'vl_lms_reject_submission';
	public const string GRADE_ACTION         = 'vl_lms_grade_submission';
	public const string REJECT_ACTION        = 'vl_lms_reject_submission';
	public const string NOTICE_QUERY_ARG     = 'vl_lms_notice';
	public const string NOTICE_GRADED        = 'graded';
	public const string NOTICE_REJECTED      = 'rejected';
	public const string NOTICE_INVALID_SCORE = 'invalid_score';
	public const string NOTICE_NOT_FOUND     = 'not_found';

	public function __construct(
		private readonly AssignmentSubmissionService $service,
		private readonly AssignmentSubmissionRepository $repository,
		private readonly EntityHierarchy $entity_hierarchy
	) {
	}

	public function render( int $submission_id ): void {
		$submission = $this->repository->find( $submission_id );

		echo '<div class="wrap">';
		echo '<h1>Деталі надсилання</h1>';
		echo '<p><a href="' . esc_url( $this->queue_url() ) . '">← Назад до черги</a></p>';

		if ( null === $submission ) {
			echo '<div class="notice notice-error"><p>Надсилання не знайдено.</p></div>';
			echo '</div>';
			return;
		}

		$assignment = get_post( $submission->assignment_id );
		$course     = $assignment instanceof WP_Post
			? $this->entity_hierarchy->resolveCourse( $assignment )
			: null;
		$user       = get_userdata( $submission->user_id );

		echo '<table class="form-table"><tbody>';
		echo '<tr><th>Студент</th><td>' . esc_html( $user instanceof \WP_User ? $user->user_login : '(видалено)' ) . '</td></tr>';
		echo '<tr><th>Завдання</th><td>' . esc_html( $assignment instanceof WP_Post ? $assignment->post_title : '(видалено)' ) . '</td></tr>';
		echo '<tr><th>Курс</th><td>' . esc_html( $course instanceof WP_Post ? $course->post_title : '—' ) . '</td></tr>';
		echo '<tr><th>Подано</th><td>' . esc_html( mysql2date( 'd.m.Y H:i', $submission->submitted_at ) ) . '</td></tr>';
		echo '<tr><th>Статус</th><td>' . esc_html( $submission->status->value ) . '</td></tr>';
		echo '</tbody></table>';

		if ( null !== $submission->submission_text ) {
			echo '<h2>Текст надсилання</h2>';
			echo '<div style="background:#fff;border:1px solid #c3c4c7;padding:12px;white-space:pre-wrap;">';
			echo esc_html( $submission->submission_text );
			echo '</div>';
		}

		if ( null !== $submission->submission_file_url ) {
			$name = $submission->submission_file_name ?? $submission->submission_file_url;
			echo '<h2>Файл</h2>';
			echo '<p><a href="' . esc_url( $submission->submission_file_url ) . '" target="_blank" rel="noopener">'
				. esc_html( $name ) . '</a></p>';
		}

		$rubric = $assignment instanceof WP_Post
			? (string) get_post_meta( $assignment->ID, '_vl_assignment_rubric', true )
			: '';
		if ( '' !== $rubric ) {
			echo '<h2>Критерії оцінювання</h2>';
			echo '<div style="background:#fff;border:1px solid #c3c4c7;padding:12px;">';
			echo wp_kses_post( $rubric );
			echo '</div>';
		}

		if ( SubmissionStatus::PENDING === $submission->status ) {
			$this->render_forms( $submission, $assignment );
		} else {
			$this->render_decided( $submission );
		}

		echo '</div>';
	}

	private function render_forms( Submission $submission, ?WP_Post $assignment ): void {
		$max_score     = $assignment instanceof WP_Post
			? (int) get_post_meta( $assignment->ID, '_vl_assignment_max_score', true )
			: 100;
		$max_score     = max( 1, $max_score );
		$passing_score = $assignment instanceof WP_Post
			? (int) get_post_meta( $assignment->ID, '_vl_assignment_passing_score', true )
			: 0;

		echo '<h2>Оцінити</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-bottom:24px;">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::GRADE_ACTION ) . '" />';
		echo '<input type="hidden" name="submission_id" value="' . esc_attr( (string) $submission->id ) . '" />';
		wp_nonce_field( self::GRADE_NONCE );
		echo '<table class="form-table"><tbody>';
		echo '<tr><th><label for="vl-lms-score">Бал</label></th><td>';
		echo '<input type="number" id="vl-lms-score" name="score" min="0" max="' . esc_attr( (string) $max_score ) . '" required />';
		echo ' <span class="description">Прохідний бал: ' . esc_html( (string) $passing_score ) . ' / ' . esc_html( (string) $max_score ) . '</span>';
		echo '</td></tr>';
		echo '<tr><th><label for="vl-lms-feedback">Коментар</label></th><td>';
		echo '<textarea id="vl-lms-feedback" name="feedback" rows="5" cols="80"></textarea>';
		echo '</td></tr>';
		echo '</tbody></table>';
		submit_button( 'Оцінити', 'primary', 'submit', false );
		echo '</form>';

		echo '<h2>Відхилити</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::REJECT_ACTION ) . '" />';
		echo '<input type="hidden" name="submission_id" value="' . esc_attr( (string) $submission->id ) . '" />';
		wp_nonce_field( self::REJECT_NONCE );
		echo '<table class="form-table"><tbody>';
		echo '<tr><th><label for="vl-lms-reject-feedback">Коментар (обовʼязково)</label></th><td>';
		echo '<textarea id="vl-lms-reject-feedback" name="feedback" rows="5" cols="80" required></textarea>';
		echo '</td></tr>';
		echo '</tbody></table>';
		submit_button( 'Відхилити', 'delete', 'submit', false );
		echo '</form>';
	}

	private function render_decided( Submission $submission ): void {
		echo '<h2>Результат</h2>';
		echo '<table class="form-table"><tbody>';
		if ( null !== $submission->score ) {
			echo '<tr><th>Бал</th><td>' . esc_html( (string) $submission->score ) . '</td></tr>';
		}
		if ( null !== $submission->feedback ) {
			echo '<tr><th>Коментар</th><td>' . esc_html( $submission->feedback ) . '</td></tr>';
		}
		if ( null !== $submission->graded_at ) {
			echo '<tr><th>Оцінено</th><td>' . esc_html( mysql2date( 'd.m.Y H:i', $submission->graded_at ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	public function handle_grade(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Доступ заборонено.', 'vl-lms' ), '', [ 'response' => 403 ] );
		}

		check_admin_referer( self::GRADE_NONCE );

		$submission_id = isset( $_POST['submission_id'] ) ? (int) $_POST['submission_id'] : 0;
		$score         = isset( $_POST['score'] ) ? (int) $_POST['score'] : 0;
		$feedback_raw  = isset( $_POST['feedback'] ) ? wp_unslash( (string) $_POST['feedback'] ) : '';
		$feedback      = '' === trim( $feedback_raw ) ? null : sanitize_textarea_field( $feedback_raw );

		try {
			$this->service->grade( $submission_id, $score, $feedback, get_current_user_id() );
		} catch ( SubmissionNotFoundException $e ) {
			$this->redirect_with_notice( self::NOTICE_NOT_FOUND );
			return;
		} catch ( InvalidScoreException $e ) {
			$this->redirect_with_notice( self::NOTICE_INVALID_SCORE );
			return;
		}

		$this->redirect_with_notice( self::NOTICE_GRADED );
	}

	public function handle_reject(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Доступ заборонено.', 'vl-lms' ), '', [ 'response' => 403 ] );
		}

		check_admin_referer( self::REJECT_NONCE );

		$submission_id = isset( $_POST['submission_id'] ) ? (int) $_POST['submission_id'] : 0;
		$feedback_raw  = isset( $_POST['feedback'] ) ? wp_unslash( (string) $_POST['feedback'] ) : '';
		$feedback      = sanitize_textarea_field( $feedback_raw );
		if ( '' === trim( $feedback ) ) {
			$this->redirect_with_notice( self::NOTICE_INVALID_SCORE );
			return;
		}

		try {
			$this->service->reject( $submission_id, $feedback, get_current_user_id() );
		} catch ( SubmissionNotFoundException $e ) {
			$this->redirect_with_notice( self::NOTICE_NOT_FOUND );
			return;
		}

		$this->redirect_with_notice( self::NOTICE_REJECTED );
	}

	private function redirect_with_notice( string $notice ): void {
		$url = add_query_arg(
			[
				'page'                 => GradingQueuePage::PAGE_SLUG,
				self::NOTICE_QUERY_ARG => $notice,
			],
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	private function queue_url(): string {
		return add_query_arg(
			[ 'page' => GradingQueuePage::PAGE_SLUG ],
			admin_url( 'admin.php' )
		);
	}
}
