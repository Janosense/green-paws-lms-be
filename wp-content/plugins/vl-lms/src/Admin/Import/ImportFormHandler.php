<?php

declare(strict_types=1);

namespace VL\LMS\Admin\Import;

use VL\LMS\Import\ImportService;
use VL\LMS\Import\Storage\ImportConfig;
use VL\LMS\Import\Storage\IntakeException;
use VL\LMS\Import\Storage\TempStore;
use VL\LMS\Import\Storage\TempStoreException;
use VL\LMS\Import\Storage\UploadIntake;
use VL\LMS\Import\Write\ImportContext;
use VL\LMS\Import\Write\Importer;
use VL\LMS\Import\Write\ImportResult;
use VL\LMS\Support\Logger;

/**
 * The `admin-post.php` handlers behind the course import screens: the upload
 * of Import — Upload, and the confirmation and the discard of Import — Preview.
 *
 * Each handler checks the capability, then the nonce, and redirects back to
 * {@see ImportPage} with a reason code, never with a message, so the page only
 * prints text it wrote itself.
 *
 * The confirmation trusts nothing the preview posted but the token and the
 * lead instructor, and it lists the instructor candidates again to check that
 * id (`docs/features/course-import/FEATURE.md` → Invariants). It re-analyses
 * the stored file through {@see ImportService}, so the import writes the plan
 * the preview showed.
 *
 * Not declared `final` — unit tests subclass to replace `redirect()` and
 * `limit_time()`.
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

	/**
	 * The reason codes this handler adds to the ones the storage exceptions
	 * carry; {@see ImportPage} turns each into its own notice.
	 */
	public const LEFTOVERS = 'import.failed_leftovers';

	public const INSTRUCTOR_INVALID = 'import.instructor_invalid';

	private const FILE_KEYS = [ 'name', 'type', 'tmp_name', 'error', 'size' ];

	public function __construct(
		private readonly UploadIntake $intake,
		private readonly TempStore $store,
		private readonly ImportService $service,
		private readonly ImportConfig $config,
		private readonly InstructorCandidates $candidates,
		private readonly Logger $logger
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
	 * Imports the previewed file and opens its report.
	 *
	 * A token that expired between the preview and the click returns to
	 * Import — Upload with the expiry notice; every other token problem
	 * returns its reason code. A failed import keeps the token folder, so the
	 * admin lands on a working preview and can try again.
	 */
	public function handle_confirm(): void {
		$this->check_request( self::CONFIRM_ACTION );

		$token = $this->posted_token();

		try {
			$handle = $this->store->open( $token, get_current_user_id() );
		} catch ( TempStoreException $exception ) {
			$this->redirect(
				$this->page_url(
					TempStoreException::EXPIRED_TOKEN === $exception->reason()
						? [ ImportPage::EXPIRED_QUERY => '1' ]
						: [ ImportPage::ERROR_QUERY => $exception->reason() ]
				)
			);
			return;
		}

		$instructor_id = $this->posted_instructor_id();
		if ( null === $instructor_id ) {
			$this->redirect(
				$this->page_url(
					[
						ImportPage::TOKEN_QUERY => $token,
						ImportPage::ERROR_QUERY => self::INSTRUCTOR_INVALID,
					]
				)
			);
			return;
		}

		$this->limit_time( $this->config->time_limit );

		$started = microtime( true );
		$result  = $this->service->import(
			$handle->dir . '/' . UploadIntake::COURSE_FILE,
			new ImportContext( $handle->token, $instructor_id, get_current_user_id() )
		);
		$seconds = round( microtime( true ) - $started, 3 );

		if ( ! $result->created ) {
			$this->log_failure( $token, $result, $seconds );
			$this->redirect(
				$this->page_url(
					[
						ImportPage::TOKEN_QUERY => $token,
						ImportPage::ERROR_QUERY => $this->failure_code( $result ),
					]
				)
			);
			return;
		}

		set_transient( ImportPage::report_key( $token ), $this->report( $result ), $this->config->report_ttl );
		$this->store->delete( $token );
		$this->log_success( $token, $result, $instructor_id, $seconds );

		$this->redirect(
			$this->page_url(
				[
					ImportPage::TOKEN_QUERY  => $token,
					ImportPage::DONE_QUERY   => '1',
					ImportPage::COURSE_QUERY => (string) $result->course_id,
				]
			)
		);
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

	/**
	 * Indirected because `set_time_limit()` is a PHP internal the unit tests
	 * cannot replace (only `time` is redefinable — `docs/TESTING.md`). A limit
	 * of `0` leaves the host's own setting alone.
	 */
	protected function limit_time( int $seconds ): void {
		if ( $seconds > 0 ) {
			set_time_limit( $seconds );
		}
	}

	/**
	 * The posted lead instructor, but only when it is one of the candidates the
	 * select offered; null otherwise. The list is built here rather than taken
	 * from the form, so a crafted post cannot name any other user.
	 */
	private function posted_instructor_id(): ?int {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce checked in check_request().
		$posted = $_POST[ self::INSTRUCTOR_FIELD ] ?? null;
		$id     = is_scalar( $posted ) ? absint( $posted ) : 0;

		if ( 0 === $id ) {
			return null;
		}

		foreach ( $this->candidates->for_user( get_current_user_id() ) as $candidate ) {
			if ( $candidate['id'] === $id ) {
				return $id;
			}
		}

		return null;
	}

	/**
	 * The code the preview shows for a failed import. A rollback that could not
	 * delete everything gets its own code, because "nothing was created" would
	 * not be true.
	 */
	private function failure_code( ImportResult $result ): string {
		if ( [] !== $result->leftovers ) {
			return self::LEFTOVERS;
		}

		return $result->code ?? Importer::WRITE_FAILED;
	}

	/**
	 * What Import — Report reads: scalars only, so the report never depends on
	 * a class shape surviving in `wp_options`.
	 *
	 * @return array{course_id: int, entities: list<array{type: string, id: int, title: string}>, issues: list<array{level: string, code: string, line: int|null, message: string}>}
	 */
	private function report( ImportResult $result ): array {
		$issues = [];
		foreach ( $result->issues->all() as $issue ) {
			$issues[] = [
				'level'   => $issue->level->value,
				'code'    => $issue->code,
				'line'    => $issue->line,
				'message' => $issue->message,
			];
		}

		return [
			'course_id' => (int) $result->course_id,
			'entities'  => $result->entities,
			'issues'    => $issues,
		];
	}

	/**
	 * One line per import: what was created and how long it took, never the
	 * file, its contents or its path.
	 */
	private function log_success( string $token, ImportResult $result, int $instructor_id, float $seconds ): void {
		$counts = [];
		foreach ( $result->entities as $entity ) {
			$counts[ $entity['type'] ] = ( $counts[ $entity['type'] ] ?? 0 ) + 1;
		}

		$this->logger->info(
			'course-import: created a private course from an uploaded file',
			[
				'token'         => $token,
				'course_id'     => $result->course_id,
				'instructor_id' => $instructor_id,
				'counts'        => $counts,
				'seconds'       => $seconds,
			]
		);
	}

	private function log_failure( string $token, ImportResult $result, float $seconds ): void {
		$this->logger->error(
			'course-import: an import failed and was rolled back',
			[
				'token'     => $token,
				'code'      => $result->code,
				'reason'    => $result->reason,
				'leftovers' => $result->leftovers,
				'seconds'   => $seconds,
			]
		);
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
