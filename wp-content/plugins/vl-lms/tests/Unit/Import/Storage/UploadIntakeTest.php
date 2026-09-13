<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Import\Storage;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Closure;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use VL\LMS\Import\Storage\Handle;
use VL\LMS\Import\Storage\ImportConfig;
use VL\LMS\Import\Storage\IntakeException;
use VL\LMS\Import\Storage\TempStore;
use VL\LMS\Import\Storage\UploadIntake;
use WP_Error;
use ZipArchive;

final class UploadIntakeTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const LIMIT = 1048576;
	private const OWNER = 5;

	private const GUARDS = [ '.htaccess', 'index.html', 'meta.json' ];

	/**
	 * Everything this test writes: `uploads/` (the WordPress uploads basedir) and `input/`.
	 */
	private string $root;

	private string $uploads;

	private int $upload_limit = self::LIMIT;

	/**
	 * The `upload_dir` callback the intake registered, captured when it is added.
	 */
	private ?Closure $upload_dir_filter = null;

	/**
	 * @var array<string, mixed>
	 */
	private array $upload_overrides = [];

	private int $uploads_handled = 0;

	private int $unzips = 0;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->root    = sys_get_temp_dir() . '/vl-lms-intake-' . bin2hex( random_bytes( 4 ) );
		$this->uploads = $this->root . '/uploads';
		mkdir( $this->uploads, 0o755, true );
		mkdir( $this->root . '/input' );

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'size_format' )->alias( static fn ( int $bytes ): string => $bytes . ' B' );
		Functions\when( 'time' )->justReturn( 1757570400 );
		Functions\when( 'wp_upload_dir' )->justReturn( $this->uploads_array() );
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
		Functions\when( 'WP_Filesystem' )->justReturn( true );
		Functions\when( 'unzip_file' )->alias( fn ( string $file, string $to ): bool => $this->unzip( $file, $to ) );
		Filters\expectAdded( 'upload_dir' )->zeroOrMoreTimes()->whenHappen(
			function ( Closure $callback ): void {
				$this->upload_dir_filter = $callback;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		$this->remove( $this->root );
		parent::tearDown();
	}

	/**
	 * @return array<string, array{0: array<string, int|string>, 1: string}>
	 */
	public static function refused_uploads(): array {
		return [
			'over the PHP limit'  => [ [ 'error' => UPLOAD_ERR_INI_SIZE ], IntakeException::TOO_LARGE ],
			'over the form limit' => [ [ 'error' => UPLOAD_ERR_FORM_SIZE ], IntakeException::TOO_LARGE ],
			'no file'             => [ [ 'error' => UPLOAD_ERR_NO_FILE ], IntakeException::UPLOAD_FAILED ],
			'no temp folder'      => [ [ 'error' => UPLOAD_ERR_NO_TMP_DIR ], IntakeException::UPLOAD_FAILED ],
			'over the limit'      => [ [ 'size' => self::LIMIT + 1 ], IntakeException::TOO_LARGE ],
			'.txt'                => [ [ 'name' => 'course.txt' ], IntakeException::WRONG_TYPE ],
			'.markdown'           => [ [ 'name' => 'course.markdown' ], IntakeException::WRONG_TYPE ],
			'no extension'        => [ [ 'name' => 'course' ], IntakeException::WRONG_TYPE ],
		];
	}

	/**
	 * @dataProvider refused_uploads
	 *
	 * @param array<string, int|string> $overrides
	 */
	public function test_a_refused_upload_creates_no_folder( array $overrides, string $reason ): void {
		$file = array_merge( $this->course_upload(), $overrides );

		$this->assert_reason( $reason, fn () => $this->intake()->accept( $file, self::OWNER ) );
		self::assertDirectoryDoesNotExist( $this->uploads . '/vl-lms-import' );
		self::assertSame( 0, $this->uploads_handled );
	}

	public function test_a_file_whose_content_wordpress_rejects_for_its_extension_is_the_wrong_type(): void {
		Functions\when( 'wp_check_filetype_and_ext' )->justReturn(
			[
				'ext'             => false,
				'type'            => false,
				'proper_filename' => false,
			]
		);

		$this->assert_reason( IntakeException::WRONG_TYPE, fn () => $this->intake()->accept( $this->course_upload(), self::OWNER ) );
		self::assertDirectoryDoesNotExist( $this->uploads . '/vl-lms-import' );
	}

	public function test_a_course_file_is_stored_as_course_md_in_a_new_token_folder(): void {
		$result = $this->intake()->accept( $this->course_upload(), self::OWNER );
		$dir    = $result->handle->dir;

		self::assertMatchesRegularExpression( TempStore::TOKEN_PATTERN, $result->handle->token );
		self::assertSame( $this->uploads . '/vl-lms-import/' . $result->handle->token, $dir );
		self::assertSame( $dir . '/course.md', $result->course_md_path );
		self::assertSame( "# Курс\n", file_get_contents( $result->course_md_path ) );
		self::assertSame( [], $result->assets );
		self::assertSame( [ '.htaccess', 'course.md', 'index.html', 'meta.json' ], $this->files( $dir ) );

		self::assertFalse( $this->upload_overrides['test_form'] );
		self::assertSame( UploadIntake::MIMES, $this->upload_overrides['mimes'] );
		self::assertNotNull( $this->upload_dir_filter );
		self::assertFalse( has_filter( 'upload_dir', $this->upload_dir_filter ) );
	}

	public function test_the_upload_dir_filter_points_wordpress_at_the_token_folder(): void {
		$result  = $this->intake()->accept( $this->course_upload(), self::OWNER );
		$token   = $result->handle->token;
		$filter  = $this->upload_dir_filter;
		$uploads = null === $filter ? [] : $filter( $this->uploads_array() );

		self::assertSame( $this->uploads . '/vl-lms-import/' . $token, $uploads['path'] );
		self::assertSame( 'https://example.test/wp-content/uploads/vl-lms-import/' . $token, $uploads['url'] );
		self::assertSame( '/vl-lms-import/' . $token, $uploads['subdir'] );
		self::assertSame( $this->uploads, $uploads['basedir'] );
	}

	public function test_a_wordpress_upload_error_deletes_the_token_folder(): void {
		Functions\when( 'wp_handle_upload' )->justReturn( [ 'error' => 'Sorry, you are not allowed to upload this file type.' ] );

		$exception = $this->assert_reason( IntakeException::UPLOAD_FAILED, fn () => $this->intake()->accept( $this->course_upload(), self::OWNER ) );

		self::assertStringContainsString( 'Sorry, you are not allowed to upload this file type.', $exception->getMessage() );
		self::assertSame( [], $this->files( $this->uploads . '/vl-lms-import' ) );
	}

	public function test_a_zip_upload_is_extracted_and_the_stored_archive_removed(): void {
		$zip = $this->zip(
			[
				'course.md'    => "# Курс\n",
				'assets/a.png' => 'png',
			]
		);

		$result = $this->intake()->accept( $this->upload( $zip, 'course.zip' ), self::OWNER );
		$dir    = $result->handle->dir;

		self::assertSame( [ 'assets/a.png' ], $result->assets );
		self::assertSame( [ '.htaccess', 'assets/a.png', 'course.md', 'index.html', 'meta.json' ], $this->files( $dir ) );
		self::assertDirectoryDoesNotExist( $dir . '/.unzip' );
		self::assertSame( 'png', file_get_contents( $dir . '/assets/a.png' ) );
	}

	public function test_extract_keeps_only_the_course_file_and_the_allowed_images(): void {
		$zip = $this->zip(
			[
				'course.md'                => "# Курс\n",
				'assets/course-slug/a.png' => 'png',
				'assets/b.JPG'             => 'jpg',
				'assets/notes.txt'         => 'notes',
				'assets/c.svg'             => '<svg/>',
				'README.md'                => 'readme',
				'lessons/x.png'            => 'png',
				'__MACOSX/._course.md'     => 'resource fork',
				'../evil.txt'              => 'evil',
				'assets/../../escape.png'  => 'png',
				'/abs.png'                 => 'png',
				'/assets/abs.png'          => 'png',
				'assets/sub\\win.png'      => 'png',
			],
			[ 'assets/link.png' => '/etc/passwd' ]
		);
		$handle = $this->store()->create( self::OWNER );

		$result = $this->intake()->extract( $handle, $zip );

		self::assertSame( $handle, $result->handle );
		self::assertSame( $handle->dir . '/course.md', $result->course_md_path );
		self::assertSame( [ 'assets/b.JPG', 'assets/course-slug/a.png' ], $result->assets );
		self::assertSame(
			[ '.htaccess', 'assets/b.JPG', 'assets/course-slug/a.png', 'course.md', 'index.html', 'meta.json' ],
			$this->files( $handle->dir )
		);
		self::assertDirectoryDoesNotExist( $handle->dir . '/.unzip' );
		self::assertNotContains( 'evil.txt', array_map( 'basename', $this->files( $this->root ) ) );
		self::assertNotContains( 'escape.png', array_map( 'basename', $this->files( $this->root ) ) );
		self::assertFileExists( $zip );
	}

	public function test_a_single_top_level_folder_is_the_course_root(): void {
		$zip    = $this->zip(
			[
				'my-course/course.md'    => "# Курс\n",
				'my-course/assets/a.png' => 'png',
				'my-course/notes.txt'    => 'notes',
			]
		);
		$handle = $this->store()->create( self::OWNER );

		$result = $this->intake()->extract( $handle, $zip );

		self::assertSame( [ 'assets/a.png' ], $result->assets );
		self::assertSame( [ '.htaccess', 'assets/a.png', 'course.md', 'index.html', 'meta.json' ], $this->files( $handle->dir ) );
	}

	/**
	 * @return array<string, array{0: array<string, string>}>
	 */
	public static function archives_without_a_course_file(): array {
		return [
			'no course.md'          => [
				[
					'README.md'    => 'readme',
					'assets/a.png' => 'png',
				],
			],
			'two levels deep'       => [ [ 'a/b/course.md' => "# Курс\n" ] ],
			'two top-level folders' => [
				[
					'one/course.md' => "# Курс\n",
					'two/course.md' => "# Курс\n",
				],
			],
			'absolute path'         => [ [ '/course.md' => "# Курс\n" ] ],
			'drive path'            => [ [ 'C:/course.md' => "# Курс\n" ] ],
			'macOS metadata only'   => [ [ '__MACOSX/course.md' => "# Курс\n" ] ],
		];
	}

	/**
	 * @dataProvider archives_without_a_course_file
	 *
	 * @param array<string, string> $entries
	 */
	public function test_an_archive_without_a_course_file_in_its_root_is_refused( array $entries ): void {
		$zip    = $this->zip( $entries );
		$handle = $this->store()->create( self::OWNER );

		$this->assert_reason( IntakeException::NO_COURSE_FILE, fn () => $this->intake()->extract( $handle, $zip ) );
		self::assertSame( 0, $this->unzips );
	}

	public function test_an_archive_that_unpacks_past_the_limit_is_refused_before_extraction(): void {
		$this->upload_limit = 1000;
		$zip                = $this->zip(
			[
				'course.md'    => str_repeat( 'a', 600 ),
				'assets/a.png' => str_repeat( 'b', 600 ),
			]
		);
		self::assertLessThan( 1000, (int) filesize( $zip ) );
		$handle = $this->store()->create( self::OWNER );

		$this->assert_reason( IntakeException::ARCHIVE_TOO_LARGE, fn () => $this->intake()->extract( $handle, $zip ) );
		self::assertSame( 0, $this->unzips );
	}

	public function test_a_file_that_is_not_a_zip_archive_is_unreadable(): void {
		$path = $this->root . '/input/course.zip';
		file_put_contents( $path, random_bytes( 64 ) );
		$handle = $this->store()->create( self::OWNER );

		$this->assert_reason( IntakeException::ARCHIVE_UNREADABLE, fn () => $this->intake()->extract( $handle, $path ) );
		self::assertSame( 0, $this->unzips );
	}

	public function test_an_unavailable_filesystem_fails_the_extraction_and_deletes_the_token_folder(): void {
		Functions\when( 'WP_Filesystem' )->justReturn( false );

		$this->assert_extraction_failure_cleans_up();
	}

	public function test_an_unzip_error_fails_the_extraction_and_deletes_the_token_folder(): void {
		Functions\when( 'unzip_file' )->justReturn( new WP_Error( 'incompatible_archive', 'Incompatible Archive.' ) );

		$exception = $this->assert_extraction_failure_cleans_up();

		self::assertStringContainsString( 'Incompatible Archive.', $exception->getMessage() );
	}

	private function assert_extraction_failure_cleans_up(): IntakeException {
		$zip = $this->zip( [ 'course.md' => "# Курс\n" ] );

		$exception = $this->assert_reason( IntakeException::EXTRACT_FAILED, fn () => $this->intake()->accept( $this->upload( $zip, 'course.zip' ), self::OWNER ) );

		self::assertSame( [], $this->files( $this->uploads . '/vl-lms-import' ) );

		return $exception;
	}

	private function intake(): UploadIntake {
		$config = $this->config();

		return new UploadIntake( $config, new TempStore( $config ) );
	}

	private function store(): TempStore {
		return new TempStore( $this->config() );
	}

	private function config(): ImportConfig {
		return new ImportConfig( $this->upload_limit, 3600, [ 'png', 'jpg', 'jpeg', 'gif', 'webp' ], 70, 300, 600 );
	}

	/**
	 * @return array{name: string, type: string, tmp_name: string, error: int, size: int}
	 */
	private function course_upload(): array {
		$path = $this->root . '/input/php-upload-md';
		file_put_contents( $path, "# Курс\n" );

		return $this->upload( $path, 'Анестезія.md' );
	}

	/**
	 * @return array{name: string, type: string, tmp_name: string, error: int, size: int}
	 */
	private function upload( string $path, string $name ): array {
		return [
			'name'     => $name,
			'type'     => 'application/octet-stream',
			'tmp_name' => $path,
			'error'    => UPLOAD_ERR_OK,
			'size'     => (int) filesize( $path ),
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
		++$this->uploads_handled;
		$this->upload_overrides = $overrides;

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

	/**
	 * Stands in for `unzip_file()` as WordPress 7.0's `_unzip_file_ziparchive()`
	 * behaves: `..` names and `__MACOSX/` are skipped, every other entry is
	 * written under the target — absolute names inside it, symlinks as regular
	 * files.
	 */
	private function unzip( string $file, string $to ): bool {
		++$this->unzips;

		$zip = new ZipArchive();
		$zip->open( $file );
		$count = $zip->count();

		for ( $index = 0; $index < $count; $index++ ) {
			$name = (string) $zip->getNameIndex( $index );
			if ( str_ends_with( $name, '/' ) || str_starts_with( $name, '__MACOSX/' ) || str_contains( $name, '../' ) ) {
				continue;
			}

			$target = $to . '/' . $name;
			if ( ! is_dir( dirname( $target ) ) ) {
				mkdir( dirname( $target ), 0o755, true );
			}
			file_put_contents( $target, (string) $zip->getFromIndex( $index ) );
		}

		$zip->close();

		return true;
	}

	/**
	 * @param array<string, string> $entries  Name => contents.
	 * @param array<string, string> $symlinks Name => link target.
	 */
	private function zip( array $entries, array $symlinks = [] ): string {
		$path = $this->root . '/input/' . bin2hex( random_bytes( 4 ) ) . '.zip';
		$zip  = new ZipArchive();
		$zip->open( $path, ZipArchive::CREATE );

		foreach ( $entries as $name => $contents ) {
			$zip->addFromString( $name, $contents );
		}

		foreach ( $symlinks as $name => $target ) {
			$zip->addFromString( $name, $target );
			$zip->setExternalAttributesName( $name, ZipArchive::OPSYS_UNIX, 0o120777 << 16 );
		}

		$zip->close();

		return $path;
	}

	private function assert_reason( string $reason, callable $call ): IntakeException {
		try {
			$call();
		} catch ( IntakeException $exception ) {
			self::assertSame( $reason, $exception->reason() );

			return $exception;
		}

		self::fail( 'Expected an IntakeException with reason ' . $reason . '.' );
	}

	/**
	 * Files under `$dir`, recursively, as sorted relative paths.
	 *
	 * @return list<string>
	 */
	private function files( string $dir ): array {
		if ( ! is_dir( $dir ) ) {
			return [];
		}

		$files = [];
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ) ) as $file ) {
			$files[] = substr( (string) $file, strlen( $dir ) + 1 );
		}
		sort( $files );

		return $files;
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
