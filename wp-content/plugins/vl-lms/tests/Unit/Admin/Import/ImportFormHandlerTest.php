<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Admin\Import;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Closure;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use VL\LMS\Import\Storage\ImportConfig;
use VL\LMS\Import\Storage\TempStore;
use VL\LMS\Import\Storage\UploadIntake;
use WP_Error;

/**
 * The upload intake and the token folders are real, over a temp uploads
 * folder; `wp_handle_upload()` is stubbed the way `UploadIntakeTest` stubs it.
 */
final class ImportFormHandlerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const OWNER = 1;
	private const OTHER = 2;
	private const NOW   = 1757570400;
	private const TTL   = 3600;
	private const LIMIT = 1048576;

	private const PAGE_URL = 'https://example.test/wp-admin/admin.php?page=vl-lms-import';

	/**
	 * Everything this test writes: `uploads/` (the WordPress uploads basedir) and `input/`.
	 */
	private string $root;

	private string $uploads;

	private int $now = self::NOW;

	private bool $can = true;

	private bool $nonce_valid = true;

	/**
	 * The capability and nonce checks in the order they ran.
	 *
	 * @var list<string>
	 */
	private array $checks = [];

	/**
	 * The `upload_dir` callback the intake registered, captured when it is added.
	 */
	private ?Closure $upload_dir_filter = null;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->root    = sys_get_temp_dir() . '/vl-lms-import-handler-' . bin2hex( random_bytes( 4 ) );
		$this->uploads = $this->root . '/uploads';
		mkdir( $this->uploads, 0o755, true );
		mkdir( $this->root . '/input' );

		$_POST  = [];
		$_FILES = [];

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( 'wp_unslash' )->returnArg( 1 );
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'size_format' )->alias( static fn ( int $bytes ): string => $bytes . ' B' );
		Functions\when( 'time' )->alias( fn (): int => $this->now );
		Functions\when( 'wp_upload_dir' )->alias( fn (): array => $this->uploads_array() );
		Functions\when( 'wp_mkdir_p' )->alias( static fn ( string $dir ): bool => is_dir( $dir ) || mkdir( $dir, 0o755, true ) );
		Functions\when( 'wp_json_encode' )->alias( static fn ( mixed $value ): string|false => json_encode( $value ) );
		Functions\when( 'wp_delete_file' )->alias( static fn ( string $file ): bool => unlink( $file ) );
		Functions\when( 'is_wp_error' )->alias( static fn ( mixed $thing ): bool => $thing instanceof WP_Error );
		Functions\when( 'wp_check_filetype_and_ext' )->alias(
			static function ( string $file, string $filename, array $mimes ): array {
				$extension = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );

				return [
					'ext'             => isset( $mimes[ $extension ] ) ? $extension : false,
					'type'            => $mimes[ $extension ] ?? false,
					'proper_filename' => false,
				];
			}
		);
		Functions\when( 'wp_handle_upload' )->alias( fn ( array $file, array $overrides ): array => $this->handle_upload( $file, $overrides ) );
		// Defined, so the intake does not require WordPress's admin include; only `.md` uploads run here.
		Functions\expect( 'unzip_file' )->never();
		Filters\expectAdded( 'upload_dir' )->zeroOrMoreTimes()->whenHappen(
			function ( Closure $callback ): void {
				$this->upload_dir_filter = $callback;
			}
		);
		Functions\when( 'admin_url' )->alias( static fn ( string $path = '' ): string => 'https://example.test/wp-admin/' . $path );
		Functions\when( 'add_query_arg' )->alias( static fn ( array $args, string $url ): string => $url . '?' . http_build_query( $args ) );
		Functions\when( 'get_current_user_id' )->justReturn( self::OWNER );
		Functions\when( 'current_user_can' )->alias(
			function ( string $capability ): bool {
				$this->checks[] = 'cap:' . $capability;

				return $this->can;
			}
		);
		Functions\when( 'check_admin_referer' )->alias(
			function ( string $action, string $field ): int {
				$this->checks[] = 'nonce:' . $action . ':' . $field;
				if ( ! $this->nonce_valid ) {
					throw new RuntimeException( 'check_admin_referer' );
				}

				return 1;
			}
		);
		Functions\when( 'wp_die' )->alias(
			static function (): void {
				throw new RuntimeException( 'wp_die' );
			}
		);
	}

	protected function tearDown(): void {
		$_POST  = [];
		$_FILES = [];
		Monkey\tearDown();
		$this->remove( $this->root );
		parent::tearDown();
	}

	/**
	 * @return array<string, array{0: bool, 1: bool, 2: string, 3: list<string>}>
	 */
	public static function refused_requests(): array {
		return [
			'without the capability' => [ false, true, 'wp_die', [ 'cap:manage_vl_lms_settings' ] ],
			'with a refused nonce'   => [ true, false, 'check_admin_referer', [ 'cap:manage_vl_lms_settings', 'nonce:vl_lms_import_upload:_vl_lms_import_nonce' ] ],
		];
	}

	/**
	 * @dataProvider refused_requests
	 *
	 * @param list<string> $checks
	 */
	public function test_a_refused_upload_request_stops_before_the_file_is_touched( bool $can, bool $nonce_valid, string $stopped_by, array $checks ): void {
		$this->can         = $can;
		$this->nonce_valid = $nonce_valid;
		$_FILES            = [ 'course_file' => $this->upload( 'course.md', "# Курс\n" ) ];
		$handler           = $this->handler();

		self::assertSame( $stopped_by, $this->stopped( fn () => $handler->handle_upload() ) );
		self::assertSame( $checks, $this->checks );
		self::assertDirectoryDoesNotExist( $this->uploads . '/vl-lms-import' );
		self::assertNull( $handler->redirected_to );
	}

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function malformed_uploads(): array {
		return [
			'no file field'   => [ [] ],
			'several files'   => [
				[
					'course_file' => [
						'name'     => [ 'a.md', 'b.md' ],
						'type'     => [ 'text/plain', 'text/plain' ],
						'tmp_name' => [ '/tmp/a', '/tmp/b' ],
						'error'    => [ 0, 0 ],
						'size'     => [ 1, 1 ],
					],
				],
			],
			'a missing key'   => [
				[
					'course_file' => [
						'name'     => 'course.md',
						'type'     => 'text/plain',
						'tmp_name' => '/tmp/a',
						'error'    => 0,
					],
				],
			],
			'not a file list' => [ [ 'course_file' => 'course.md' ] ],
		];
	}

	/**
	 * @dataProvider malformed_uploads
	 *
	 * @param array<string, mixed> $files
	 */
	public function test_an_upload_that_is_not_one_file_is_a_failed_upload( array $files ): void {
		$_FILES  = $files;
		$handler = $this->handler();

		$handler->handle_upload();

		self::assertSame( self::PAGE_URL . '&error=upload.failed', $handler->redirected_to );
		self::assertDirectoryDoesNotExist( $this->uploads . '/vl-lms-import' );
	}

	public function test_a_refused_upload_returns_to_the_upload_screen_with_its_reason(): void {
		$_FILES  = [ 'course_file' => $this->upload( 'course.txt', "# Курс\n" ) ];
		$handler = $this->handler();

		$handler->handle_upload();

		self::assertSame( self::PAGE_URL . '&error=upload.wrong_type', $handler->redirected_to );
		self::assertDirectoryDoesNotExist( $this->uploads . '/vl-lms-import' );
	}

	public function test_an_accepted_upload_opens_the_preview_of_its_token(): void {
		$_FILES  = [ 'course_file' => $this->upload( 'Анестезія.md', "# Курс\n" ) ];
		$handler = $this->handler();

		$handler->handle_upload();

		self::assertMatchesRegularExpression( '/^' . preg_quote( self::PAGE_URL, '/' ) . '&token=([0-9a-f]{32})$/', (string) $handler->redirected_to );
		self::assertSame( [ 'cap:manage_vl_lms_settings', 'nonce:vl_lms_import_upload:_vl_lms_import_nonce' ], $this->checks );

		$token  = substr( (string) $handler->redirected_to, -32 );
		$handle = $this->store()->open( $token, self::OWNER );
		self::assertSame( "# Курс\n", file_get_contents( $handle->dir . '/course.md' ) );
	}

	/**
	 * @return array<string, array{0: bool, 1: bool, 2: string}>
	 */
	public static function refused_discards(): array {
		return [
			'without the capability' => [ false, true, 'wp_die' ],
			'with a refused nonce'   => [ true, false, 'check_admin_referer' ],
		];
	}

	/**
	 * @dataProvider refused_discards
	 */
	public function test_a_refused_discard_request_keeps_the_folder( bool $can, bool $nonce_valid, string $stopped_by ): void {
		$handle            = $this->store()->create( self::OWNER );
		$this->can         = $can;
		$this->nonce_valid = $nonce_valid;
		$_POST             = [ 'token' => $handle->token ];
		$handler           = $this->handler();

		self::assertSame( $stopped_by, $this->stopped( fn () => $handler->handle_discard() ) );
		self::assertDirectoryExists( $handle->dir );
		self::assertNull( $handler->redirected_to );
	}

	public function test_discarding_the_admin_s_upload_deletes_its_folder(): void {
		$handle  = $this->store()->create( self::OWNER );
		$_POST   = [ 'token' => $handle->token ];
		$handler = $this->handler();

		$handler->handle_discard();

		self::assertSame( [ 'cap:manage_vl_lms_settings', 'nonce:vl_lms_import_discard:_vl_lms_import_nonce' ], $this->checks );
		self::assertDirectoryDoesNotExist( $handle->dir );
		self::assertSame( self::PAGE_URL . '&discarded=1', $handler->redirected_to );
	}

	public function test_discarding_an_expired_upload_deletes_its_folder(): void {
		$handle    = $this->store()->create( self::OWNER );
		$this->now = self::NOW + self::TTL + 60;
		$_POST     = [ 'token' => $handle->token ];
		$handler   = $this->handler();

		$handler->handle_discard();

		self::assertDirectoryDoesNotExist( $handle->dir );
		self::assertSame( self::PAGE_URL . '&discarded=1', $handler->redirected_to );
	}

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function tokens_without_a_folder(): array {
		return [
			'no token'          => [ [] ],
			'an empty token'    => [ [ 'token' => '' ] ],
			'a malformed token' => [ [ 'token' => '../vl-lms-import' ] ],
			'a token array'     => [ [ 'token' => [ '0123456789abcdef0123456789abcdef' ] ] ],
			'an unknown token'  => [ [ 'token' => 'fedcba9876543210fedcba9876543210' ] ],
		];
	}

	/**
	 * @dataProvider tokens_without_a_folder
	 *
	 * @param array<string, mixed> $post
	 */
	public function test_discarding_a_token_without_a_folder_is_already_done( array $post ): void {
		$kept    = $this->store()->create( self::OWNER );
		$_POST   = $post;
		$handler = $this->handler();

		$handler->handle_discard();

		self::assertSame( self::PAGE_URL . '&discarded=1', $handler->redirected_to );
		self::assertDirectoryExists( $kept->dir );
	}

	public function test_another_user_s_upload_is_not_discarded(): void {
		$handle  = $this->store()->create( self::OTHER );
		$_POST   = [ 'token' => $handle->token ];
		$handler = $this->handler();

		$handler->handle_discard();

		self::assertDirectoryExists( $handle->dir );
		self::assertSame( self::PAGE_URL . '&error=storage.foreign_token', $handler->redirected_to );
	}

	private function handler(): TestableImportFormHandler {
		$config = $this->config();
		$store  = new TempStore( $config );

		return new TestableImportFormHandler( new UploadIntake( $config, $store ), $store );
	}

	private function store(): TempStore {
		return new TempStore( $this->config() );
	}

	private function config(): ImportConfig {
		return new ImportConfig( self::LIMIT, self::TTL, [ 'png', 'jpg', 'jpeg', 'gif', 'webp' ], 70, 300, 600 );
	}

	/**
	 * Runs a handler that must stop with `wp_die()` or a refused nonce.
	 *
	 * @return string What stopped it.
	 */
	private function stopped( callable $call ): string {
		try {
			$call();
		} catch ( RuntimeException $exception ) {
			return $exception->getMessage();
		}

		self::fail( 'Expected the request to be refused.' );
	}

	/**
	 * @return array{name: string, type: string, tmp_name: string, error: int, size: int}
	 */
	private function upload( string $name, string $contents ): array {
		$path = $this->root . '/input/php-upload-' . bin2hex( random_bytes( 4 ) );
		file_put_contents( $path, $contents );

		return [
			'name'     => $name,
			'type'     => 'application/octet-stream',
			'tmp_name' => $path,
			'error'    => UPLOAD_ERR_OK,
			'size'     => strlen( $contents ),
		];
	}

	/**
	 * @return array<string, string|false>
	 */
	private function uploads_array(): array {
		return [
			'path'    => $this->uploads . '/2026/09',
			'url'     => 'https://example.test/wp-content/uploads/2026/09',
			'subdir'  => '/2026/09',
			'basedir' => $this->uploads,
			'baseurl' => 'https://example.test/wp-content/uploads',
			'error'   => false,
		];
	}

	/**
	 * Stands in for `wp_handle_upload()`: the registered `upload_dir` callback
	 * picks the folder, the `unique_filename_callback` the name.
	 *
	 * @param array{name: string, tmp_name: string} $file
	 * @param array<string, mixed>                  $overrides
	 *
	 * @return array{file: string, url: string, type: string}
	 */
	private function handle_upload( array $file, array $overrides ): array {
		self::assertNotNull( $this->upload_dir_filter );
		self::assertIsCallable( $overrides['unique_filename_callback'] );

		$uploads = ( $this->upload_dir_filter )( $this->uploads_array() );
		$name    = $overrides['unique_filename_callback']( $uploads['path'], $file['name'], '.' . pathinfo( $file['name'], PATHINFO_EXTENSION ) );
		copy( $file['tmp_name'], $uploads['path'] . '/' . $name );

		return [
			'file' => $uploads['path'] . '/' . $name,
			'url'  => $uploads['url'] . '/' . $name,
			'type' => 'text/plain',
		];
	}

	private function remove( string $path ): void {
		if ( is_link( $path ) || is_file( $path ) ) {
			unlink( $path );
			return;
		}

		if ( ! is_dir( $path ) ) {
			return;
		}

		foreach ( array_diff( (array) scandir( $path ), [ '.', '..' ] ) as $entry ) {
			$this->remove( $path . '/' . $entry );
		}

		rmdir( $path );
	}
}
