<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Import\Storage;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Import\Storage\ImportConfig;
use VL\LMS\Import\Storage\TempStore;
use VL\LMS\Import\Storage\TempStoreException;

final class TempStoreTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const NOW   = 1757570400;
	private const TTL   = 3600;
	private const OWNER = 5;

	/**
	 * The uploads `basedir` of this test: a fresh folder under the system temp dir.
	 */
	private string $uploads;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->uploads = sys_get_temp_dir() . '/vl-lms-temp-store-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->uploads, 0o755, true );

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'time' )->justReturn( self::NOW );
		Functions\when( 'wp_upload_dir' )->justReturn(
			[
				'path'    => $this->uploads . '/2026/09',
				'url'     => 'https://example.test/wp-content/uploads/2026/09',
				'subdir'  => '/2026/09',
				'basedir' => $this->uploads,
				'baseurl' => 'https://example.test/wp-content/uploads',
				'error'   => false,
			]
		);
		Functions\when( 'wp_mkdir_p' )->alias( static fn ( string $dir ): bool => is_dir( $dir ) || mkdir( $dir, 0o755, true ) );
		Functions\when( 'wp_json_encode' )->alias( static fn ( mixed $value ): string|false => json_encode( $value ) );
		Functions\when( 'wp_delete_file' )->alias( static fn ( string $file ): bool => unlink( $file ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		$this->remove( $this->uploads );
		parent::tearDown();
	}

	public function test_create_makes_a_token_folder_with_its_guard_files_and_owner(): void {
		$handle = $this->store()->create( self::OWNER );

		self::assertMatchesRegularExpression( TempStore::TOKEN_PATTERN, $handle->token );
		self::assertSame( $this->uploads . '/vl-lms-import/' . $handle->token, $handle->dir );
		self::assertSame( [ '.htaccess', 'index.html', 'meta.json' ], $this->listing( $handle->dir ) );
		self::assertSame( "Deny from all\n", file_get_contents( $handle->dir . '/.htaccess' ) );
		self::assertSame( '', file_get_contents( $handle->dir . '/index.html' ) );
		self::assertSame(
			[
				'user_id'    => self::OWNER,
				'created_at' => self::NOW,
			],
			json_decode( (string) file_get_contents( $handle->dir . '/meta.json' ), true )
		);
	}

	public function test_each_create_gets_its_own_token(): void {
		$store = $this->store();

		self::assertNotSame( $store->create( self::OWNER )->token, $store->create( self::OWNER )->token );
	}

	public function test_create_reports_an_unwritable_uploads_folder(): void {
		Functions\when( 'wp_mkdir_p' )->justReturn( false );

		$this->assert_reason( TempStoreException::UNWRITABLE, fn () => $this->store()->create( self::OWNER ) );
		self::assertDirectoryDoesNotExist( $this->uploads . '/vl-lms-import' );
	}

	public function test_the_owner_opens_the_folder_until_the_ttl_has_passed(): void {
		$store   = $this->store();
		$created = $store->create( self::OWNER );

		Functions\when( 'time' )->justReturn( self::NOW + self::TTL );
		self::assertEquals( $created, $store->open( $created->token, self::OWNER ) );

		Functions\when( 'time' )->justReturn( self::NOW + self::TTL + 1 );
		$this->assert_reason( TempStoreException::EXPIRED_TOKEN, fn () => $store->open( $created->token, self::OWNER ) );
		self::assertDirectoryExists( $created->dir );
	}

	public function test_another_user_cannot_open_the_folder_even_after_it_expired(): void {
		$store   = $this->store();
		$created = $store->create( self::OWNER );

		$this->assert_reason( TempStoreException::FOREIGN_TOKEN, fn () => $store->open( $created->token, self::OWNER + 1 ) );

		Functions\when( 'time' )->justReturn( self::NOW + self::TTL + 1 );
		$this->assert_reason( TempStoreException::FOREIGN_TOKEN, fn () => $store->open( $created->token, self::OWNER + 1 ) );
	}

	public function test_a_well_formed_token_without_a_folder_is_unknown(): void {
		$this->assert_reason( TempStoreException::UNKNOWN_TOKEN, fn () => $this->store()->open( str_repeat( 'ab', 16 ), self::OWNER ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function malformed_tokens(): array {
		$token = str_repeat( 'ab', 16 );

		return [
			'parent folder'    => [ '../' . $token ],
			'uppercase'        => [ strtoupper( $token ) ],
			'31 characters'    => [ substr( $token, 1 ) ],
			'trailing newline' => [ $token . "\n" ],
			'empty'            => [ '' ],
		];
	}

	/**
	 * @dataProvider malformed_tokens
	 */
	public function test_a_malformed_token_is_unknown_before_any_path_is_built( string $token ): void {
		// A valid owner file one level above the base folder, where `../<token>` would lead.
		mkdir( $this->uploads . '/vl-lms-import' );
		$outside = $this->uploads . '/' . str_repeat( 'ab', 16 );
		mkdir( $outside );
		file_put_contents(
			$outside . '/meta.json',
			(string) json_encode(
				[
					'user_id'    => self::OWNER,
					'created_at' => self::NOW,
				]
			)
		);

		$this->assert_reason( TempStoreException::UNKNOWN_TOKEN, fn () => $this->store()->open( $token, self::OWNER ) );
	}

	/**
	 * @return array<string, array{0: string|null}>
	 */
	public static function broken_meta_files(): array {
		return [
			'missing'        => [ null ],
			'not JSON'       => [ 'owner: 5' ],
			'string user id' => [ '{"user_id":"5","created_at":1757570400}' ],
			'no created_at'  => [ '{"user_id":5}' ],
		];
	}

	/**
	 * @dataProvider broken_meta_files
	 */
	public function test_a_missing_or_broken_meta_file_makes_the_token_unknown( ?string $contents ): void {
		$store   = $this->store();
		$created = $store->create( self::OWNER );

		unlink( $created->dir . '/meta.json' );
		if ( null !== $contents ) {
			file_put_contents( $created->dir . '/meta.json', $contents );
		}

		$this->assert_reason( TempStoreException::UNKNOWN_TOKEN, fn () => $store->open( $created->token, self::OWNER ) );
	}

	public function test_delete_removes_the_folder_with_everything_in_it(): void {
		$store   = $this->store();
		$doomed  = $store->create( self::OWNER );
		$sibling = $store->create( self::OWNER );
		mkdir( $doomed->dir . '/assets/a', 0o755, true );
		file_put_contents( $doomed->dir . '/assets/a/b.png', 'png' );

		$store->delete( $doomed->token );

		self::assertDirectoryDoesNotExist( $doomed->dir );
		self::assertSame( [ '.htaccess', 'index.html', 'meta.json' ], $this->listing( $sibling->dir ) );
	}

	public function test_delete_ignores_malformed_and_unknown_tokens(): void {
		$store   = $this->store();
		$created = $store->create( self::OWNER );

		$store->delete( '..' );
		$store->delete( '../' . $created->token );
		$store->delete( '' );
		$store->delete( str_repeat( '0', 32 ) );

		self::assertSame( [ '.htaccess', 'index.html', 'meta.json' ], $this->listing( $created->dir ) );
	}

	public function test_sweep_removes_expired_folders_and_keeps_fresh_ones(): void {
		$store = $this->store();
		$old   = $store->create( self::OWNER );
		Functions\when( 'time' )->justReturn( self::NOW + 1800 );
		$fresh = $store->create( self::OWNER );

		Functions\when( 'time' )->justReturn( self::NOW + self::TTL + 1 );
		$store->sweep();

		self::assertDirectoryDoesNotExist( $old->dir );
		self::assertDirectoryExists( $fresh->dir );
	}

	public function test_sweep_ages_a_folder_without_meta_file_by_its_modification_time(): void {
		$store = $this->store();
		$stale = $store->create( self::OWNER );
		$fresh = $store->create( self::OWNER );
		unlink( $stale->dir . '/meta.json' );
		unlink( $fresh->dir . '/meta.json' );
		// A minute of margin: under Patchwork's stream wrapper `touch()` lands one second late.
		touch( $stale->dir, self::NOW - self::TTL - 60 );
		touch( $fresh->dir, self::NOW - 60 );

		$store->sweep();

		self::assertDirectoryDoesNotExist( $stale->dir );
		self::assertDirectoryExists( $fresh->dir );
	}

	public function test_sweep_leaves_other_names_in_the_base_folder_alone(): void {
		$base = $this->uploads . '/vl-lms-import';
		mkdir( $base . '/keep-me', 0o755, true );
		file_put_contents( $base . '/notes.txt', 'notes' );
		file_put_contents( $base . '/' . str_repeat( 'cd', 16 ), 'a file, not a token folder' );
		touch( $base . '/keep-me', self::NOW - self::TTL - 60 );

		$this->store()->sweep();

		self::assertSame( [ 'cdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcd', 'keep-me', 'notes.txt' ], $this->listing( $base ) );
	}

	public function test_sweep_without_a_base_folder_does_nothing(): void {
		$this->store()->sweep();

		self::assertDirectoryDoesNotExist( $this->uploads . '/vl-lms-import' );
	}

	private function store(): TempStore {
		return new TempStore( new ImportConfig( 1048576, self::TTL, [ 'png' ], 70 ) );
	}

	private function assert_reason( string $reason, callable $call ): void {
		try {
			$call();
		} catch ( TempStoreException $exception ) {
			self::assertSame( $reason, $exception->reason() );

			return;
		}

		self::fail( 'Expected a TempStoreException with reason ' . $reason . '.' );
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
