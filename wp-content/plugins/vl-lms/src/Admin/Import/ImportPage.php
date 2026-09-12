<?php

declare(strict_types=1);

namespace VL\LMS\Admin\Import;

use VL\LMS\Import\ImportService;
use VL\LMS\Import\Issue\ImportIssue;
use VL\LMS\Import\Issue\IssueLevel;
use VL\LMS\Import\Issue\IssueList;
use VL\LMS\Import\Plan\ImportPlan;
use VL\LMS\Import\Storage\Handle;
use VL\LMS\Import\Storage\ImportConfig;
use VL\LMS\Import\Storage\IntakeException;
use VL\LMS\Import\Storage\TempStore;
use VL\LMS\Import\Storage\TempStoreException;
use VL\LMS\Import\Storage\UploadIntake;
use VL\LMS\Import\Validation\CourseLevel;
use VL\LMS\Import\Write\MediaImporter;

/**
 * wp-admin «Імпорт курсу» (`?page=vl-lms-import`): the screens Import — Upload
 * and, for an upload's token (`&token=…`), Import — Preview. Import — Report is
 * added in Sprint 1 Step 8.
 *
 * Every render sweeps the expired token folders (`docs/DECISIONS.md`
 * 2026-09-11 — temp folder), after opening the requested token, so an expired
 * token reads as expired rather than unknown. The preview analyses the stored
 * `course.md` on each render. It shows what the import would create and every
 * warning, including the image warnings the import will give. Its confirm
 * form carries only the token and the chosen lead instructor.
 *
 * @author Tymofii Synianskyi
 */
final class ImportPage {

	public const PAGE_SLUG       = 'vl-lms-import';
	public const CAPABILITY      = 'manage_vl_lms_settings';
	public const TOKEN_QUERY     = 'token';
	public const ERROR_QUERY     = 'error';
	public const DISCARDED_QUERY = 'discarded';
	public const EXPIRED_QUERY   = 'expired';

	public function __construct(
		private readonly ImportConfig $config,
		private readonly TempStore $store,
		private readonly ImportService $service,
		private readonly MediaImporter $media,
		private readonly InstructorCandidates $candidates
	) {
	}

	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Доступ заборонено.', 'vl-lms' ), '', [ 'response' => 403 ] );
		}

		$token       = $this->query( self::TOKEN_QUERY );
		$handle      = null;
		$token_error = null;

		if ( '' !== $token ) {
			try {
				$handle = $this->store->open( $token, get_current_user_id() );
			} catch ( TempStoreException $exception ) {
				$token_error = $exception->getMessage();
			}
		}

		$this->store->sweep();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Імпорт курсу', 'vl-lms' ) . '</h1>';
		echo '<hr class="wp-header-end" />';

		if ( null === $handle ) {
			$this->render_upload( $token_error );
		} else {
			$this->render_preview( $handle );
		}

		echo '</div>';
	}

	/**
	 * Import — Upload.
	 *
	 * @param string|null $token_error Why the requested token could not be opened.
	 */
	private function render_upload( ?string $token_error ): void {
		if ( null !== $token_error ) {
			$this->notice( 'error', $token_error );
		}

		$error = $this->query( self::ERROR_QUERY );
		if ( '' !== $error ) {
			$this->notice( 'error', $this->error_message( $error ) );
		}

		if ( '' !== $this->query( self::DISCARDED_QUERY ) ) {
			$this->notice( 'success', __( 'Імпорт скасовано, завантажений файл видалено.', 'vl-lms' ) );
		}

		if ( '' !== $this->query( self::EXPIRED_QUERY ) ) {
			$this->notice( 'warning', TempStoreException::expired_token()->getMessage() );
		}

		// PHP refuses a file above its own limit before the importer's filtered limit applies.
		$limit = min( $this->config->max_upload_bytes, wp_max_upload_size() );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" enctype="multipart/form-data">';
		echo '<input type="hidden" name="action" value="' . esc_attr( ImportFormHandler::UPLOAD_ACTION ) . '" />';
		wp_nonce_field( ImportFormHandler::UPLOAD_ACTION, ImportFormHandler::NONCE_FIELD );

		echo '<table class="form-table" role="presentation"><tbody><tr>';
		echo '<th scope="row"><label for="vl-lms-import-file">' . esc_html__( 'Файл курсу', 'vl-lms' ) . '</label></th>';
		echo '<td>';
		echo '<input type="file" id="vl-lms-import-file" name="' . esc_attr( ImportFormHandler::FILE_FIELD ) . '" accept=".md,.zip" required />';
		echo '<p class="description">' . esc_html__( 'Формат файлу курсу v1', 'vl-lms' ) . '</p>';
		echo '<p class="description">' . esc_html(
			sprintf(
				/* translators: %s: the largest course file accepted, e.g. "8 MB" */
				__( 'Максимальний розмір файлу: %s', 'vl-lms' ),
				(string) size_format( $limit )
			)
		) . '</p>';
		echo '</td></tr></tbody></table>';

		submit_button( __( 'Завантажити й перевірити', 'vl-lms' ) );
		echo '</form>';
	}

	/**
	 * Import — Preview: the errors that block the import, or what it would create.
	 */
	private function render_preview( Handle $handle ): void {
		$analysis = $this->service->analyse( $handle->dir . '/' . UploadIntake::COURSE_FILE );

		if ( null === $analysis->plan ) {
			$this->render_errors( $analysis->issues, $handle->token );
			return;
		}

		$this->render_plan( $analysis->plan, $handle );
	}

	private function render_errors( IssueList $issues, string $token ): void {
		$this->notice( 'error', __( 'Файл курсу містить помилки, тому курс не буде створено. Виправте файл і завантажте його знову.', 'vl-lms' ) );

		echo '<table class="wp-list-table widefat striped">';
		echo '<thead><tr><th scope="col">' . esc_html__( 'Рядок', 'vl-lms' ) . '</th><th scope="col">' . esc_html__( 'Помилка', 'vl-lms' ) . '</th></tr></thead>';
		echo '<tbody>';
		foreach ( $issues->all() as $issue ) {
			if ( IssueLevel::ERROR === $issue->level ) {
				printf( '<tr><td>%s</td><td>%s</td></tr>', esc_html( $this->line( $issue ) ), esc_html( $issue->message ) );
			}
		}
		echo '</tbody></table>';

		$this->render_discard_form( $token, __( 'Завантажити інший файл', 'vl-lms' ), 'primary' );
	}

	private function render_plan( ImportPlan $plan, Handle $handle ): void {
		// The image warnings join the plan's issues as Importer::run() joins them.
		$issues = new IssueList();
		foreach ( $plan->issues->all() as $issue ) {
			$issues->add( $issue );
		}

		$found  = count( $this->media->check( $plan->images, $handle->dir, $issues ) );
		$counts = $plan->counts();

		echo '<div class="vl-lms-import-cards" style="display:flex;gap:16px;margin:16px 0;flex-wrap:wrap;">';
		$this->render_card( __( 'Модулів', 'vl-lms' ), $counts['modules'] );
		$this->render_card( __( 'Уроків', 'vl-lms' ), $counts['lessons'] );
		$this->render_card( __( 'Тестів', 'vl-lms' ), $counts['quizzes'] );
		$this->render_card( __( 'Питань', 'vl-lms' ), $counts['questions'] );
		$this->render_card( __( 'Знайдено зображень', 'vl-lms' ), $found );
		$this->render_card( __( 'Відсутніх зображень', 'vl-lms' ), $counts['images'] - $found );
		echo '</div>';

		$this->render_course_data( $plan );
		$this->render_warnings( $issues );
		$this->render_confirm_form( $plan, $handle->token );
		$this->render_discard_form( $handle->token, __( 'Скасувати', 'vl-lms' ), 'secondary' );
	}

	private function render_course_data( ImportPlan $plan ): void {
		$course   = $plan->course;
		$source   = $plan->source;
		$duration = null === $course->duration_hours
			? null
			/* translators: %s: the course duration in hours, e.g. "1,5" */
			: sprintf( __( '%s год', 'vl-lms' ), number_format_i18n( $course->duration_hours, 1 ) );

		$rows = [
			[ __( 'Назва', 'vl-lms' ), $course->title ],
			[ __( 'Slug', 'vl-lms' ), $course->slug ],
			[ __( 'Автор матеріалу', 'vl-lms' ), $source->author ],
			[ __( 'Організація автора', 'vl-lms' ), $source->author_org ],
			[ __( 'Рівень', 'vl-lms' ), $this->level( $course->difficulty_slug ) ],
			[ __( 'Категорія', 'vl-lms' ), $course->category_slug ],
			[ __( 'Теги', 'vl-lms' ), implode( ', ', $course->tag_slugs ) ],
			[ __( 'Тривалість', 'vl-lms' ), $duration ],
			[ __( 'Прохідний бал тестів', 'vl-lms' ), $course->pass_percent . ' %' ],
			[ __( 'Статус файлу', 'vl-lms' ), $source->status ],
			[ __( 'Версія файлу', 'vl-lms' ), (string) $source->version ],
			[ __( 'Тип джерела', 'vl-lms' ), $source->source_type ],
			[ __( 'Назва джерела', 'vl-lms' ), $source->source_title ],
			[ __( 'Дата джерела', 'vl-lms' ), $source->source_date ],
		];

		echo '<h2>' . esc_html__( 'Дані курсу', 'vl-lms' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';
		foreach ( $rows as [ $label, $value ] ) {
			printf(
				'<tr><th scope="row">%s</th><td>%s</td></tr>',
				esc_html( $label ),
				esc_html( null === $value || '' === $value ? '—' : $value )
			);
		}
		echo '</tbody></table>';
	}

	private function render_warnings( IssueList $issues ): void {
		echo '<h2>' . esc_html__( 'Попередження та примітки', 'vl-lms' ) . '</h2>';

		$all = $issues->all();
		if ( [] === $all ) {
			echo '<p>' . esc_html__( 'Попереджень і приміток немає.', 'vl-lms' ) . '</p>';
			return;
		}

		echo '<table class="wp-list-table widefat striped">';
		echo '<thead><tr><th scope="col">' . esc_html__( 'Рядок', 'vl-lms' ) . '</th><th scope="col">' . esc_html__( 'Тип', 'vl-lms' ) . '</th><th scope="col">' . esc_html__( 'Повідомлення', 'vl-lms' ) . '</th></tr></thead>';
		echo '<tbody>';
		foreach ( $all as $issue ) {
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( $this->line( $issue ) ),
				esc_html( IssueLevel::INFO === $issue->level ? __( 'Примітка', 'vl-lms' ) : __( 'Попередження', 'vl-lms' ) ),
				esc_html( $issue->message )
			);
		}
		echo '</tbody></table>';
	}

	private function render_confirm_form( ImportPlan $plan, string $token ): void {
		$user_id    = get_current_user_id();
		$candidates = $this->candidates->for_user( $user_id );
		$selected   = InstructorCandidates::preselect( $candidates, $plan->source->author, $user_id );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( ImportFormHandler::CONFIRM_ACTION ) . '" />';
		echo '<input type="hidden" name="' . esc_attr( ImportFormHandler::TOKEN_FIELD ) . '" value="' . esc_attr( $token ) . '" />';
		wp_nonce_field( ImportFormHandler::CONFIRM_ACTION, ImportFormHandler::NONCE_FIELD );

		echo '<table class="form-table" role="presentation"><tbody><tr>';
		echo '<th scope="row"><label for="vl-lms-import-instructor">' . esc_html__( 'Автор (головний інструктор)', 'vl-lms' ) . '</label></th>';
		echo '<td>';
		echo '<select id="vl-lms-import-instructor" name="' . esc_attr( ImportFormHandler::INSTRUCTOR_FIELD ) . '">';
		foreach ( $candidates as $candidate ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( (string) $candidate['id'] ),
				$candidate['id'] === $selected ? ' selected="selected"' : '',
				esc_html( $candidate['display_name'] . ' (' . $candidate['user_login'] . ')' )
			);
		}
		echo '</select>';
		echo '<p class="description">' . esc_html(
			sprintf(
				/* translators: %s: the author named in the course file */
				__( 'Автор у файлі курсу: %s', 'vl-lms' ),
				$plan->source->author
			)
		) . '</p>';
		echo '</td></tr></tbody></table>';

		submit_button( __( 'Імпортувати', 'vl-lms' ) );
		echo '</form>';
	}

	/**
	 * @param string $button_type `primary` or `secondary`, as `submit_button()` takes it.
	 */
	private function render_discard_form( string $token, string $label, string $button_type ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( ImportFormHandler::DISCARD_ACTION ) . '" />';
		echo '<input type="hidden" name="' . esc_attr( ImportFormHandler::TOKEN_FIELD ) . '" value="' . esc_attr( $token ) . '" />';
		wp_nonce_field( ImportFormHandler::DISCARD_ACTION, ImportFormHandler::NONCE_FIELD );
		submit_button( $label, $button_type );
		echo '</form>';
	}

	private function render_card( string $label, int $value ): void {
		echo '<div class="vl-lms-import-card" style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:16px;min-width:160px;">';
		echo '<div style="font-size:12px;color:#646970;text-transform:uppercase;">' . esc_html( $label ) . '</div>';
		echo '<div style="font-size:28px;font-weight:600;">' . esc_html( (string) $value ) . '</div>';
		echo '</div>';
	}

	/**
	 * @param string $type The notice modifier: `error`, `warning` or `success`.
	 */
	private function notice( string $type, string $message ): void {
		printf( '<div class="notice notice-%s"><p>%s</p></div>', esc_attr( $type ), esc_html( $message ) );
	}

	/**
	 * The message for a reason code the handlers put in `?error=`: the text the
	 * exception itself carries. An unknown code gets the generic upload
	 * failure, so nothing from the URL is ever printed.
	 */
	private function error_message( string $code ): string {
		$limit = $this->config->max_upload_bytes;

		$exception = match ( $code ) {
			IntakeException::TOO_LARGE           => IntakeException::too_large( $limit ),
			IntakeException::WRONG_TYPE          => IntakeException::wrong_type(),
			IntakeException::ARCHIVE_UNSUPPORTED => IntakeException::archive_unsupported(),
			IntakeException::ARCHIVE_UNREADABLE  => IntakeException::archive_unreadable(),
			IntakeException::ARCHIVE_TOO_LARGE   => IntakeException::archive_too_large( $limit ),
			IntakeException::NO_COURSE_FILE      => IntakeException::no_course_file(),
			IntakeException::EXTRACT_FAILED      => IntakeException::extract_failed(),
			TempStoreException::UNKNOWN_TOKEN    => TempStoreException::unknown_token(),
			TempStoreException::EXPIRED_TOKEN    => TempStoreException::expired_token(),
			TempStoreException::FOREIGN_TOKEN    => TempStoreException::foreign_token(),
			TempStoreException::UNWRITABLE       => TempStoreException::unwritable(),
			default                              => IntakeException::upload_failed(),
		};

		return $exception->getMessage();
	}

	/**
	 * The file's `level` and the `vl_difficulty` slug it maps to, e.g. `practitioner → advanced`.
	 */
	private function level( string $difficulty_slug ): string {
		foreach ( CourseLevel::cases() as $level ) {
			if ( $level->difficulty_slug() === $difficulty_slug ) {
				return $level->value . ' → ' . $difficulty_slug;
			}
		}

		return $difficulty_slug;
	}

	private function line( ImportIssue $issue ): string {
		return null === $issue->line ? '—' : (string) $issue->line;
	}

	private function query( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page state; nothing is changed from the URL.
		$value = $_GET[ $key ] ?? '';

		return is_string( $value ) ? sanitize_text_field( wp_unslash( $value ) ) : '';
	}
}
