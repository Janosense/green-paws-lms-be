<?php

declare(strict_types=1);

namespace VL\LMS\Admin\Import;

use VL\LMS\Import\Storage\IntakeException;
use VL\LMS\Import\Storage\TempStore;
use VL\LMS\Import\Storage\TempStoreException;
use VL\LMS\Import\Storage\UploadIntake;

/**
 * The `admin-post.php` handlers behind the course import screens: the upload
 * of Import — Upload and the discard of Import — Preview. The confirmation the
 * preview posts to {@see self::CONFIRM_ACTION} is handled from Sprint 1 Step 8.
 *
 * Each handler checks the capability, then the nonce, and redirects back to
 * {@see ImportPage} with a reason code, never with a message, so the page only
 * prints text it wrote itself.
 *
 * Not declared `final` — unit tests subclass to replace `redirect()`.
 *
 * @author Tymofii Synianskyi
 */
class ImportFormHandler {

	public const UPLOAD_ACTION    = 'vl_lms_import_upload';
	public const CONFIRM_ACTION   = 'vl_lms_import_confirm';
	public const DISCARD_ACTION   = 'vl_lms_import_discard';
	public const NONCE_FIELD      = '_vl_lms_import_nonce';
	public const FILE_FIELD       = 'course_file';
	public const TOKEN_FIELD      = 'token';
	public const INSTRUCTOR_FIELD = 'instructor_id';

	private const FILE_KEYS = [ 'name', 'type', 'tmp_name', 'error', 'size' ];

	public function __construct(
		private readonly UploadIntake $intake,
		private readonly TempStore $store
	) {
	}

	/**
	 * Accepts one uploaded `.md` or `.zip` into a new token folder owned by the
	 * admin and opens its preview. The file is analysed by the preview, not
	 * here: nothing of this request but the folder reaches it.
	 */
	public function handle_upload(): void {
		$this->check_request( self::UPLOAD_ACTION );

		$file = $this->uploaded_file();
		if ( null === $file ) {
			$this->redirect( $this->page_url( [ ImportPage::ERROR_QUERY => IntakeException::UPLOAD_FAILED ] ) );
			return;
		}

		try {
			$result = $this->intake->accept( $file, get_current_user_id() );
		} catch ( IntakeException | TempStoreException $exception ) {
			$this->redirect( $this->page_url( [ ImportPage::ERROR_QUERY => $exception->reason() ] ) );
			return;
		}

		$this->redirect( $this->page_url( [ ImportPage::TOKEN_QUERY => $result->handle->token ] ) );
	}

	/**
	 * Deletes the admin's token folder and returns to Import — Upload. A folder
	 * that expired or is already gone counts as discarded; another user's
	 * folder is left alone.
	 */
	public function handle_discard(): void {
		$this->check_request( self::DISCARD_ACTION );

		$token = $this->posted_token();

		try {
			$this->store->open( $token, get_current_user_id() );
		} catch ( TempStoreException $exception ) {
			if ( TempStoreException::FOREIGN_TOKEN === $exception->reason() ) {
				$this->redirect( $this->page_url( [ ImportPage::ERROR_QUERY => $exception->reason() ] ) );
				return;
			}
		}

		$this->store->delete( $token );
		$this->redirect( $this->page_url( [ ImportPage::DISCARDED_QUERY => '1' ] ) );
	}

	/**
	 * Indirected so unit tests can subclass and capture the target URL
	 * without `exit`.
	 */
	protected function redirect( string $url ): void {
		wp_safe_redirect( $url );
		exit;
	}

	private function check_request( string $action ): void {
		if ( ! current_user_can( ImportPage::CAPABILITY ) ) {
			wp_die( esc_html__( 'Доступ заборонено.', 'vl-lms' ), '', [ 'response' => 403 ] );
		}

		check_admin_referer( $action, self::NONCE_FIELD );
	}

	/**
	 * The `course_file` upload when it is exactly one file: every `$_FILES` key
	 * present with a scalar value.
	 *
	 * @return array{name: string, type: string, tmp_name: string, error: int, size: int}|null
	 */
	private function uploaded_file(): ?array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce checked in check_request(); UploadIntake validates the file.
		$file = $_FILES[ self::FILE_FIELD ] ?? null;
		if ( ! is_array( $file ) ) {
			return null;
		}

		foreach ( self::FILE_KEYS as $key ) {
			if ( ! isset( $file[ $key ] ) || ! is_scalar( $file[ $key ] ) ) {
				return null;
			}
		}

		return [
			'name'     => (string) $file['name'],
			'type'     => (string) $file['type'],
			'tmp_name' => (string) $file['tmp_name'],
			'error'    => (int) $file['error'],
			'size'     => (int) $file['size'],
		];
	}

	private function posted_token(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce checked in check_request().
		$token = $_POST[ self::TOKEN_FIELD ] ?? '';

		return is_string( $token ) ? sanitize_text_field( wp_unslash( $token ) ) : '';
	}

	/**
	 * @param array<string, string> $args
	 */
	private function page_url( array $args ): string {
		return add_query_arg( [ 'page' => ImportPage::PAGE_SLUG ] + $args, admin_url( 'admin.php' ) );
	}
}
