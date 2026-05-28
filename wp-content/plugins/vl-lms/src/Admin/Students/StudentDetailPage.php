<?php

declare(strict_types=1);

namespace VL\LMS\Admin\Students;

use VL\LMS\Domain\Enrollment\Enrollment;
use VL\LMS\Domain\Enrollment\EnrollmentSource;
use VL\LMS\Domain\Enrollment\EnrollmentStatus;
use VL\LMS\Repositories\CertificateRepository;
use VL\LMS\Repositories\EnrollmentRepository;
use WP_Post;
use WP_User;

/**
 * Admin per-student detail screen (`?page=vl-lms-students&action=view&id=N`).
 *
 * Two tabs over a single student:
 *   - "Аналітика" (default): summary cards + a per-course progress /
 *     completion / certificate table.
 *   - "Курси": the student's enrollments with a revoke action, plus a
 *     grant-access form posting to {@see StudentEnrollmentFormHandler}.
 *
 * Read access gates on the same cap as the list (`vl_view_all_enrollments`);
 * the grant/revoke mutations live in the form handler behind the stronger
 * `vl_manage_enrollments`. Mirrors {@see \VL\LMS\Admin\Groups\GroupDetailPage}.
 *
 * Not declared `final` — unit tests subclass to bypass `wp_die`.
 *
 * @author Tymofii Synianskyi
 */
class StudentDetailPage {

	private const string DEFAULT_TAB = 'analytics';

	public function __construct(
		private readonly EnrollmentRepository $enrollments,
		private readonly CertificateRepository $certificates
	) {
	}

	public function render(): void {
		if ( ! current_user_can( StudentsListPage::CAPABILITY ) ) {
			$this->forbidden();
			return;
		}

		$user_id = $this->request_id();
		$user    = null === $user_id ? null : get_userdata( $user_id );
		if ( null === $user_id || ! $user instanceof WP_User || ! in_array( 'student', (array) $user->roles, true ) ) {
			$this->render_not_found();
			return;
		}

		$tab = $this->request_tab();

		echo '<div class="wrap">';
		printf(
			'<h1 class="wp-heading-inline">%s</h1>',
			esc_html(
				sprintf(
					/* translators: %s: student display name */
					__( 'Студент: %s', 'vl-lms' ),
					$this->student_name( $user )
				)
			)
		);
		printf(
			' <a href="%s" class="page-title-action">%s</a>',
			esc_url( admin_url( 'admin.php?page=' . StudentsListPage::PAGE_SLUG ) ),
			esc_html__( '← До списку', 'vl-lms' )
		);
		echo '<hr class="wp-header-end" />';

		$this->render_notice();
		$this->render_tabs( $user_id, $tab );

		if ( 'courses' === $tab ) {
			$this->render_courses_tab( $user );
		} else {
			$this->render_analytics_tab( $user );
		}

		echo '</div>';
	}

	private function render_tabs( int $user_id, string $current ): void {
		$tabs = [
			'analytics' => __( 'Аналітика', 'vl-lms' ),
			'courses'   => __( 'Курси', 'vl-lms' ),
		];

		echo '<h2 class="nav-tab-wrapper">';
		foreach ( $tabs as $slug => $label ) {
			$url = add_query_arg(
				[
					'page'   => StudentsListPage::PAGE_SLUG,
					'action' => 'view',
					'id'     => $user_id,
					'tab'    => $slug,
				],
				admin_url( 'admin.php' )
			);
			printf(
				'<a href="%s" class="nav-tab%s">%s</a>',
				esc_url( $url ),
				$slug === $current ? ' nav-tab-active' : '',
				esc_html( $label )
			);
		}
		echo '</h2>';
	}

	private function render_analytics_tab( WP_User $user ): void {
		$enrollments  = $this->enrollments->list_for_user( (int) $user->ID );
		$certificates = $this->certificates->list_for_user( (int) $user->ID );

		$active    = 0;
		$completed = 0;
		foreach ( $enrollments as $enrollment ) {
			if ( EnrollmentStatus::ACTIVE === $enrollment->status ) {
				++$active;
			}
			if ( EnrollmentStatus::COMPLETED === $enrollment->status ) {
				++$completed;
			}
		}

		echo '<div class="vl-lms-analytics-cards" style="display:flex;gap:16px;margin:16px 0;flex-wrap:wrap;">';
		$this->render_card( __( 'Записів усього', 'vl-lms' ), count( $enrollments ) );
		$this->render_card( __( 'Активних', 'vl-lms' ), $active );
		$this->render_card( __( 'Завершено', 'vl-lms' ), $completed );
		$this->render_card( __( 'Сертифікатів', 'vl-lms' ), count( $certificates ) );
		echo '</div>';

		echo '<section class="vl-admin-card">';
		echo '<h2>' . esc_html__( 'Курси студента', 'vl-lms' ) . '</h2>';

		if ( [] === $enrollments ) {
			echo '<p>' . esc_html__( 'Студент ще не записаний на жоден курс.', 'vl-lms' ) . '</p>';
			echo '</section>';
			return;
		}

		echo '<table class="widefat striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Курс', 'vl-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Статус', 'vl-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Прогрес', 'vl-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Записано', 'vl-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Завершено', 'vl-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Сертифікат', 'vl-lms' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $enrollments as $enrollment ) {
			$has_cert = null !== $this->certificates->find_active_for_user_in_course( (int) $user->ID, $enrollment->course_id );
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s%%</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( $this->course_title( $enrollment->course_id ) ),
				esc_html( $this->status_label( $enrollment->status ) ),
				esc_html( (string) $enrollment->progress_pct ),
				esc_html( $enrollment->enrolled_at ),
				esc_html( null === $enrollment->completed_at ? '—' : $enrollment->completed_at ),
				$has_cert ? esc_html__( 'Так', 'vl-lms' ) : '—'
			);
		}

		echo '</tbody></table>';
		echo '</section>';
	}

	private function render_courses_tab( WP_User $user ): void {
		$enrollments = $this->enrollments->list_for_user( (int) $user->ID );

		echo '<section class="vl-admin-card">';
		echo '<h2>' . esc_html__( 'Записи на курси', 'vl-lms' ) . '</h2>';
		$this->render_enrollments_table( $user, $enrollments );
		$this->render_grant_form( $user );
		echo '</section>';
	}

	/**
	 * @param list<Enrollment> $enrollments
	 */
	private function render_enrollments_table( WP_User $user, array $enrollments ): void {
		if ( [] === $enrollments ) {
			echo '<p>' . esc_html__( 'Студент ще не записаний на жоден курс.', 'vl-lms' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Курс', 'vl-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Статус', 'vl-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Джерело', 'vl-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Ціна', 'vl-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Записано', 'vl-lms' ) . '</th>';
		echo '<th></th>';
		echo '</tr></thead><tbody>';

		foreach ( $enrollments as $enrollment ) {
			$can_revoke = EnrollmentStatus::ACTIVE === $enrollment->status || EnrollmentStatus::COMPLETED === $enrollment->status;
			$action     = '—';
			if ( $can_revoke && current_user_can( StudentEnrollmentFormHandler::CAPABILITY ) ) {
				$revoke_url = wp_nonce_url(
					add_query_arg(
						[
							'action'    => StudentEnrollmentFormHandler::ACTION_REVOKE,
							'user_id'   => (int) $user->ID,
							'course_id' => $enrollment->course_id,
						],
						admin_url( 'admin-post.php' )
					),
					StudentEnrollmentFormHandler::ACTION_REVOKE . '_' . (int) $user->ID . '_' . $enrollment->course_id
				);

				$confirm = wp_json_encode( __( 'Відкликати доступ до курсу для цього студента?', 'vl-lms' ) );
				$confirm = is_string( $confirm ) ? $confirm : '"?"';

				$action = sprintf(
					'<a href="%s" class="button-link delete" onclick="return confirm(%s);">%s</a>',
					esc_url( $revoke_url ),
					esc_attr( $confirm ),
					esc_html__( 'Відкликати', 'vl-lms' )
				);
			}

			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( $this->course_title( $enrollment->course_id ) ),
				esc_html( $this->status_label( $enrollment->status ) ),
				esc_html( $this->source_label( $enrollment->source ) ),
				esc_html( $this->course_price_label( $enrollment->course_id ) ),
				esc_html( $enrollment->enrolled_at ),
				$action // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Pre-escaped link markup above.
			);
		}

		echo '</tbody></table>';
	}

	private function render_grant_form( WP_User $user ): void {
		if ( ! current_user_can( StudentEnrollmentFormHandler::CAPABILITY ) ) {
			return;
		}

		echo '<h3>' . esc_html__( 'Надати доступ до курсу', 'vl-lms' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Відкриває доступ до будь-якого опублікованого курсу (зокрема платного) без оплати.', 'vl-lms' ) . '</p>';

		// `vl-admin-group-add-course` is the selector the shared admin-groups.js
		// course picker binds to; reused here so no new JS is needed.
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="vl-admin-group-add-course">';
		echo '<input type="hidden" name="action" value="' . esc_attr( StudentEnrollmentFormHandler::ACTION_GRANT ) . '" />';
		echo '<input type="hidden" name="user_id" value="' . esc_attr( (string) $user->ID ) . '" />';
		wp_nonce_field( StudentEnrollmentFormHandler::ACTION_GRANT . '_' . (int) $user->ID );

		echo '<p>';
		echo '<label for="vl-student-add-course-search" class="screen-reader-text">' . esc_html__( 'Пошук курсу', 'vl-lms' ) . '</label>';
		echo '<input type="text" id="vl-student-add-course-search" class="regular-text vl-admin-course-search" placeholder="' . esc_attr__( 'Шукати опублікований курс…', 'vl-lms' ) . '" autocomplete="off" />';
		echo '<input type="hidden" id="vl-student-add-course-id" name="course_id" value="" required />';
		echo '<span class="vl-admin-course-search-results"></span>';
		echo '</p>';

		submit_button( __( 'Надати доступ', 'vl-lms' ), 'secondary', '', false );
		echo '</form>';
	}

	private function render_card( string $label, int $value ): void {
		echo '<div class="vl-lms-analytics-card" style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:16px;min-width:160px;">';
		echo '<div style="font-size:12px;color:#646970;text-transform:uppercase;">' . esc_html( $label ) . '</div>';
		echo '<div style="font-size:28px;font-weight:600;">' . esc_html( (string) $value ) . '</div>';
		echo '</div>';
	}

	private function render_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice key.
		$notice = isset( $_GET['notice'] ) ? sanitize_key( wp_unslash( (string) $_GET['notice'] ) ) : '';
		if ( '' === $notice ) {
			return;
		}

		[ $level, $message ] = match ( $notice ) {
			'course_granted'       => [ 'success', __( 'Доступ до курсу надано.', 'vl-lms' ) ],
			'course_revoked'       => [ 'success', __( 'Доступ до курсу відкликано.', 'vl-lms' ) ],
			'user_not_found'       => [ 'error', __( 'Користувача не знайдено або він не є студентом.', 'vl-lms' ) ],
			'course_not_found'     => [ 'error', __( 'Курс не знайдено або не опубліковано.', 'vl-lms' ) ],
			'enrollment_not_found' => [ 'error', __( 'Активний запис на курс не знайдено.', 'vl-lms' ) ],
			default                => [ '', '' ],
		};

		if ( '' === $level ) {
			return;
		}

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $level ),
			esc_html( $message )
		);
	}

	private function render_not_found(): void {
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Студента не знайдено', 'vl-lms' ) . '</h1>';
		echo '<div class="notice notice-error"><p>' . esc_html__( 'Перевірте, чи правильне посилання, або поверніться до списку.', 'vl-lms' ) . '</p></div>';
		printf(
			'<a href="%s" class="button">%s</a>',
			esc_url( admin_url( 'admin.php?page=' . StudentsListPage::PAGE_SLUG ) ),
			esc_html__( '← До списку', 'vl-lms' )
		);
		echo '</div>';
	}

	private function student_name( WP_User $user ): string {
		$first = (string) get_user_meta( $user->ID, 'first_name', true );
		$last  = (string) get_user_meta( $user->ID, 'last_name', true );
		$full  = trim( $first . ' ' . $last );
		return '' !== $full ? $full : (string) $user->display_name;
	}

	private function course_title( int $course_id ): string {
		$post = get_post( $course_id );
		if ( $post instanceof WP_Post ) {
			return (string) $post->post_title;
		}
		/* translators: %d: course ID */
		return sprintf( __( '#%d (видалено)', 'vl-lms' ), $course_id );
	}

	private function course_price_label( int $course_id ): string {
		$price = (float) get_post_meta( $course_id, '_vl_course_price', true );
		if ( $price <= 0.0 ) {
			return __( 'Безкоштовний', 'vl-lms' );
		}
		return number_format_i18n( $price, 2 ) . ' UAH';
	}

	private function status_label( EnrollmentStatus $status ): string {
		return match ( $status ) {
			EnrollmentStatus::ACTIVE    => __( 'Активний', 'vl-lms' ),
			EnrollmentStatus::COMPLETED => __( 'Завершено', 'vl-lms' ),
			EnrollmentStatus::EXPIRED   => __( 'Прострочено', 'vl-lms' ),
			EnrollmentStatus::REVOKED   => __( 'Відкликано', 'vl-lms' ),
			EnrollmentStatus::REFUNDED  => __( 'Повернено', 'vl-lms' ),
		};
	}

	private function source_label( EnrollmentSource $source ): string {
		return match ( $source ) {
			EnrollmentSource::MANUAL      => __( 'Вручну', 'vl-lms' ),
			EnrollmentSource::PURCHASE    => __( 'Покупка', 'vl-lms' ),
			EnrollmentSource::GROUP       => __( 'Група', 'vl-lms' ),
			EnrollmentSource::GIFT        => __( 'Подарунок', 'vl-lms' ),
			EnrollmentSource::GRANT       => __( 'Наданий доступ', 'vl-lms' ),
			EnrollmentSource::SELF_SIGNUP => __( 'Самозапис', 'vl-lms' ),
		};
	}

	private function request_id(): ?int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing param.
		if ( ! isset( $_GET['id'] ) ) {
			return null;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Sanitised below.
		$raw = wp_unslash( (string) $_GET['id'] );
		if ( ! is_numeric( $raw ) ) {
			return null;
		}
		$id = (int) $raw;
		return $id > 0 ? $id : null;
	}

	private function request_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing param.
		$raw = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : '';
		return 'courses' === $raw ? 'courses' : self::DEFAULT_TAB;
	}

	/**
	 * Indirected so unit tests can subclass and bypass `wp_die`.
	 */
	protected function forbidden(): void {
		wp_die( esc_html__( 'Доступ заборонено.', 'vl-lms' ), '', [ 'response' => 403 ] );
	}
}
