<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Import\Write;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use VL\LMS\Import\Convert\ImageRef;
use VL\LMS\Import\Convert\MarkdownToHtml;
use VL\LMS\Import\Issue\ImportIssue;
use VL\LMS\Import\Issue\IssueList;
use VL\LMS\Import\Write\ImportContext;
use VL\LMS\Import\Write\ImportLedger;
use VL\LMS\Import\Write\MediaImporter;
use WP_Error;

/**
 * The import folder is a real folder; the media library is not: the
 * `media_handle_sideload` stub records what it gets and consumes the copy
 * the way WordPress does.
 */
final class MediaImporterTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const TOKEN         = '0123456789abcdef0123456789abcdef';
	private const INSTRUCTOR_ID = 5;
	private const ADMIN_ID      = 1;
	private const COURSE_ID     = 77;
	private const UPLOADS_URL   = 'https://example.test/wp-content/uploads/2026/09/';

	/**
	 * Everything this test writes: the import folder `token/`, the temp copies, files outside the folder.
	 */
	private string $root;

	/**
	 * The import folder, which holds `assets/`.
	 */
	private string $source;

	/**
	 * @var list<array{name: string, tmp_name: string, bytes: string, post_id: int, desc: string|null, post_data: array<string, mixed>}>
	 */
	private array $sideloads = [];

	/**
	 * @var array<int, WP_Error> Sideload call number => the error it returns.
	 */
	private array $sideload_errors = [];

	/**
	 * @var array<int, string> Attachment id => file name.
	 */
	private array $names = [];

	private int $tempnams = 0;

	private ?string $tempnam_path = null;

	private bool $no_urls = false;

	private IssueList $issues;

	private ImportLedger $ledger;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->root   = sys_get_temp_dir() . '/vl-lms-media-' . bin2hex( random_bytes( 4 ) );
		$this->source = $this->root . '/' . self::TOKEN;
		mkdir( $this->source, 0o755, true );

		$this->issues = new IssueList();
		$this->ledger = new ImportLedger();

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'esc_url' )->returnArg( 1 );
		Functions\when( 'wp_slash' )->alias( [ self::class, 'slash' ] );
		Functions\when( 'is_wp_error' )->alias( static fn ( mixed $thing ): bool => $thing instanceof WP_Error );
		Functions\when( 'wp_basename' )->alias( static fn ( string $path ): string => urldecode( basename( str_replace( [ '%2F', '%5C' ], '/', urlencode( $path ) ) ) ) );
		Functions\when( 'wp_delete_file' )->alias( static fn ( string $file ): bool => unlink( $file ) );
		Functions\when( 'wp_tempnam' )->alias(
			function (): string {
				++$this->tempnams;

				return $this->tempnam_path ?? (string) tempnam( $this->root, 'sideload-' );
			}
		);
		Functions\when( 'media_handle_sideload' )->alias(
			fn ( array $file, int $post_id, ?string $desc, array $post_data ): int|WP_Error => $this->sideload( $file, $post_id, $desc, $post_data )
		);
		Functions\when( 'wp_get_attachment_url' )->alias(
			fn ( int $id ): string|false => $this->no_urls ? false : self::UPLOADS_URL . $this->names[ $id ]
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		$this->remove( $this->root );
		parent::tearDown();
	}

	public function test_uploads_a_copy_of_each_referenced_image_attached_to_the_course(): void {
		$this->put( 'assets/course/a.png', 'png-a' );
		$this->put( 'assets/b.png', 'png-b' );

		$urls = $this->import(
			[
				new ImageRef( 'assets/course/a.png', 'assets/course/a.png', ' Схема ', 5 ),
				new ImageRef( 'assets/b.png', 'assets/b.png', '', 9 ),
			]
		);

		self::assertSame(
			[
				'assets/course/a.png' => self::UPLOADS_URL . 'a.png',
				'assets/b.png'        => self::UPLOADS_URL . 'b.png',
			],
			$urls
		);
		$post_data = [
			'post_author' => self::INSTRUCTOR_ID,
			'meta_input'  => [ '_vl_import_id' => self::TOKEN ],
		];
		self::assertSame(
			[
				[ 'a.png', 'png-a', self::COURSE_ID, 'Схема', $post_data ],
				[ 'b.png', 'png-b', self::COURSE_ID, 'b', $post_data ],
			],
			array_map(
				static fn ( array $sideload ): array => [ $sideload['name'], $sideload['bytes'], $sideload['post_id'], $sideload['desc'], $sideload['post_data'] ],
				$this->sideloads
			)
		);
		self::assertSame(
			[
				[
					'type'  => 'attachment',
					'id'    => 901,
					'title' => 'Схема',
				],
				[
					'type'  => 'attachment',
					'id'    => 902,
					'title' => 'b',
				],
			],
			$this->ledger->summary()
		);
		self::assertSame( 'png-a', file_get_contents( $this->source . '/assets/course/a.png' ) );
		self::assertSame( 'png-b', file_get_contents( $this->source . '/assets/b.png' ) );
		self::assertSame( [ self::TOKEN ], $this->listing( $this->root ), 'No temp copy is left behind.' );
		self::assertSame( [], $this->issues->all() );
	}

	public function test_a_referenced_image_the_folder_lacks_and_a_file_nobody_references_are_warnings(): void {
		$this->put( 'assets/b.png', 'png-b' );
		$this->put( 'assets/unused.png', 'png-unused' );
		$this->put( 'assets/deep/unused.webp', 'webp-unused' );

		$urls = $this->import(
			[
				new ImageRef( 'assets/b.png', 'assets/b.png', '', 3 ),
				new ImageRef( 'assets/missing.png', 'assets/missing.png', '', 12 ),
			]
		);

		self::assertSame( [ 'assets/b.png' => self::UPLOADS_URL . 'b.png' ], $urls );
		self::assertSame( [ 'b.png' ], array_column( $this->sideloads, 'name' ) );
		self::assertSame(
			[
				[ MediaImporter::IMAGE_UNUSED, null, 'assets/deep/unused.webp' ],
				[ MediaImporter::IMAGE_UNUSED, null, 'assets/unused.png' ],
				[ MediaImporter::IMAGE_MISSING, 12, 'assets/missing.png' ],
			],
			$this->issue_rows()
		);
	}

	public function test_check_compares_the_images_with_the_folder_and_uploads_nothing(): void {
		$this->put( 'assets/b.png', 'png-b' );
		$this->put( 'assets/unused.png', 'png-unused' );

		$found = ( new MediaImporter() )->check(
			[
				new ImageRef( 'assets/missing.png', 'assets/missing.png', '', 12 ),
				new ImageRef( 'assets/b.png', 'assets/b.png', '', 3 ),
			],
			$this->source,
			$this->issues
		);

		self::assertSame( [ 'assets/b.png' ], $found );
		self::assertSame(
			[
				[ MediaImporter::IMAGE_UNUSED, null, 'assets/unused.png' ],
				[ MediaImporter::IMAGE_MISSING, 12, 'assets/missing.png' ],
			],
			$this->issue_rows()
		);
		self::assertSame( 0, $this->tempnams );
		self::assertSame( [], $this->sideloads );
		self::assertSame( [], $this->ledger->summary() );
	}

	public function test_without_an_assets_folder_every_image_is_missing_and_nothing_is_uploaded(): void {
		$urls = $this->import(
			[
				new ImageRef( 'assets/a.png', 'assets/a.png', '', 3 ),
				new ImageRef( 'assets/b.png', 'assets/b.png', '', 9 ),
			]
		);

		self::assertSame( [], $urls );
		self::assertSame( 0, $this->tempnams );
		self::assertSame( [], $this->sideloads );
		self::assertSame(
			[
				[ MediaImporter::IMAGE_MISSING, 3, 'assets/a.png' ],
				[ MediaImporter::IMAGE_MISSING, 9, 'assets/b.png' ],
			],
			$this->issue_rows()
		);
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function references_outside_the_folder(): array {
		return [
			'parent segment' => [ 'assets/../outside.png' ],
			'symlink'        => [ 'assets/link.png' ],
		];
	}

	/**
	 * @dataProvider references_outside_the_folder
	 */
	public function test_a_reference_that_leads_outside_the_assets_folder_is_missing( string $path ): void {
		mkdir( $this->source . '/assets' );
		$this->put( 'outside.png', 'png-outside' );
		file_put_contents( $this->root . '/secret.png', 'png-secret' );
		symlink( $this->root . '/secret.png', $this->source . '/assets/link.png' );

		$urls = $this->import( [ new ImageRef( $path, $path, '', 4 ) ] );

		self::assertSame( [], $urls );
		self::assertSame( [], $this->sideloads );
		self::assertSame( [ [ MediaImporter::IMAGE_MISSING, 4, $path ] ], $this->issue_rows() );
	}

	public function test_the_title_reaches_wordpress_slashed(): void {
		$this->put( 'assets/scan.png', 'png-scan' );

		$this->import( [ new ImageRef( 'assets/scan.png', 'assets/scan.png', 'C:\scan', 2 ) ] );

		self::assertSame( 'C:\\\\scan', $this->sideloads[0]['desc'] );
		self::assertSame( 'C:\scan', $this->ledger->summary()[0]['title'] );
	}

	public function test_a_refused_sideload_throws_its_message_and_leaves_the_earlier_attachment_in_the_ledger(): void {
		$this->put( 'assets/a.png', 'png-a' );
		$this->put( 'assets/b.png', 'not an image' );
		$this->sideload_errors[2] = new WP_Error( 'upload_error', 'Sorry, you are not allowed to upload this file type.' );

		$error = $this->import_failure(
			[
				new ImageRef( 'assets/a.png', 'assets/a.png', '', 2 ),
				new ImageRef( 'assets/b.png', 'assets/b.png', '', 3 ),
			]
		);

		self::assertSame( 'Sorry, you are not allowed to upload this file type.', $error->getMessage() );
		self::assertSame( [ 901 ], array_column( $this->ledger->summary(), 'id' ) );
		self::assertSame( [ self::TOKEN ], $this->listing( $this->root ), 'The refused copy is deleted.' );
	}

	public function test_an_attachment_without_a_url_throws_after_it_is_recorded(): void {
		$this->put( 'assets/a.png', 'png-a' );
		$this->no_urls = true;

		$error = $this->import_failure( [ new ImageRef( 'assets/a.png', 'assets/a.png', '', 2 ) ] );

		self::assertStringContainsString( 'assets/a.png', $error->getMessage() );
		self::assertSame( [ 901 ], array_column( $this->ledger->summary(), 'id' ) );
	}

	public function test_a_copy_that_cannot_be_written_throws_before_any_sideload(): void {
		$this->put( 'assets/a.png', 'png-a' );
		$this->tempnam_path = $this->root . '/missing/copy.tmp';

		$error = $this->import_failure( [ new ImageRef( 'assets/a.png', 'assets/a.png', '', 2 ) ] );

		self::assertStringContainsString( 'assets/a.png', $error->getMessage() );
		self::assertSame( [], $this->sideloads );
		self::assertSame( [], $this->ledger->summary() );
	}

	public function test_rewrite_points_the_src_of_uploaded_images_at_their_urls_and_leaves_the_rest(): void {
		$markdown = implode(
			"\n\n",
			[
				'![a](assets/course/a.png) ![a again](assets/course/a.png)',
				'![ф](assets/ф.png) ![s](<assets/my scan.png>) ![x](assets/a&b.png)',
				'![cdn](https://cdn.example.test/assets/course/a.png) ![gone](assets/missing.png)',
				'[посилання](assets/course/a.png) `src="assets/course/a.png"`',
			]
		);
		$html = ( new MarkdownToHtml() )->render( $markdown, 1 )->html;

		$rewritten = ( new MediaImporter() )->rewrite(
			$html,
			[
				'assets/course/a.png' => self::UPLOADS_URL . 'a.png',
				'assets/ф.png'        => self::UPLOADS_URL . 'f.png',
				'assets/my scan.png'  => self::UPLOADS_URL . 'my-scan.png',
				'assets/a&b.png'      => self::UPLOADS_URL . 'ab.png',
			]
		);

		self::assertSame(
			'<p><img src="' . self::UPLOADS_URL . 'a.png" alt="a" /> <img src="' . self::UPLOADS_URL . 'a.png" alt="a again" /></p>' . "\n"
			. '<p><img src="' . self::UPLOADS_URL . 'f.png" alt="ф" /> <img src="' . self::UPLOADS_URL . 'my-scan.png" alt="s" /> <img src="' . self::UPLOADS_URL . 'ab.png" alt="x" /></p>' . "\n"
			. '<p><img src="https://cdn.example.test/assets/course/a.png" alt="cdn" /> <img src="assets/missing.png" alt="gone" /></p>' . "\n"
			. '<p><a href="assets/course/a.png">посилання</a> <code>src=&quot;assets/course/a.png&quot;</code></p>' . "\n",
			$rewritten
		);
	}

	/**
	 * `wp_slash()` as WordPress implements it: `addslashes()` on every string, recursively.
	 */
	public static function slash( mixed $value ): mixed {
		if ( is_array( $value ) ) {
			return array_map( [ self::class, 'slash' ], $value );
		}

		return is_string( $value ) ? addslashes( $value ) : $value;
	}

	/**
	 * @param list<ImageRef> $images
	 *
	 * @return array<string, string>
	 */
	private function import( array $images ): array {
		return ( new MediaImporter() )->import(
			$images,
			$this->source,
			self::COURSE_ID,
			new ImportContext( self::TOKEN, self::INSTRUCTOR_ID, self::ADMIN_ID ),
			$this->ledger,
			$this->issues
		);
	}

	/**
	 * @param list<ImageRef> $images
	 */
	private function import_failure( array $images ): RuntimeException {
		try {
			$this->import( $images );
		} catch ( RuntimeException $exception ) {
			return $exception;
		}

		self::fail( 'Expected the import to throw.' );
	}

	/**
	 * Mimics `media_handle_sideload()`: WordPress moves the temp file into the
	 * uploads folder, so a successful call consumes it; a refused one leaves it.
	 *
	 * @param array{name: string, tmp_name: string} $file
	 * @param array<string, mixed>                  $post_data
	 */
	private function sideload( array $file, int $post_id, ?string $desc, array $post_data ): int|WP_Error {
		self::assertFileExists( $file['tmp_name'] );
		self::assertStringStartsNotWith( $this->source, $file['tmp_name'], 'WordPress gets a copy, never the folder\'s file.' );

		$this->sideloads[] = [
			'name'      => $file['name'],
			'tmp_name'  => $file['tmp_name'],
			'bytes'     => (string) file_get_contents( $file['tmp_name'] ),
			'post_id'   => $post_id,
			'desc'      => $desc,
			'post_data' => $post_data,
		];
		$call = count( $this->sideloads );

		if ( isset( $this->sideload_errors[ $call ] ) ) {
			return $this->sideload_errors[ $call ];
		}

		unlink( $file['tmp_name'] );
		$id                 = 900 + $call;
		$this->names[ $id ] = $file['name'];

		return $id;
	}

	/**
	 * @return list<array{0: string, 1: int|null, 2: string}> Code, line and the quoted path of each issue.
	 */
	private function issue_rows(): array {
		return array_map(
			static function ( ImportIssue $issue ): array {
				preg_match( '/«([^»]+)»/u', $issue->message, $quoted );

				return [ $issue->code, $issue->line, $quoted[1] ?? '' ];
			},
			$this->issues->all()
		);
	}

	private function put( string $relative, string $contents ): void {
		$path = $this->source . '/' . $relative;
		if ( ! is_dir( dirname( $path ) ) ) {
			mkdir( dirname( $path ), 0o755, true );
		}
		file_put_contents( $path, $contents );
	}

	/**
	 * @return list<string>
	 */
	private function listing( string $dir ): array {
		$entries = array_values( array_diff( (array) scandir( $dir ), [ '.', '..' ] ) );
		sort( $entries );

		return $entries;
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
