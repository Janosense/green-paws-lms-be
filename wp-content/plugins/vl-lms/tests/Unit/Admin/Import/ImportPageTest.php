<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Admin\Import;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use VL\LMS\Admin\Import\ImportPage;
use VL\LMS\Admin\Import\InstructorCandidates;
use VL\LMS\Import\Convert\CourseHtmlBuilder;
use VL\LMS\Import\Convert\LessonHtmlBuilder;
use VL\LMS\Import\Convert\MarkdownToHtml;
use VL\LMS\Import\Convert\ModuleHtmlBuilder;
use VL\LMS\Import\ImportService;
use VL\LMS\Import\Issue\IssueLevel;
use VL\LMS\Import\Parser\CourseDocumentParser;
use VL\LMS\Import\Parser\FrontMatterParser;
use VL\LMS\Import\Parser\QuizBlockParser;
use VL\LMS\Import\Storage\Handle;
use VL\LMS\Import\Storage\ImportConfig;
use VL\LMS\Import\Storage\IntakeException;
use VL\LMS\Import\Storage\TempStore;
use VL\LMS\Import\Storage\TempStoreException;
use VL\LMS\Import\Validation\CourseValidator;
use VL\LMS\Import\Write\Importer;
use VL\LMS\Import\Write\MediaImporter;
use WP_User;

/**
 * Renders the page over real token folders and the real analysis pipeline;
 * WordPress's markup helpers are stubbed to print plain markup.
 */
final class ImportPageTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const FIXTURES = __DIR__ . '/../../../Fixtures/Import/';

	private const ADMIN_ID = 1;
	private const OTHER_ID = 2;
	private const NOW      = 1757570400;
	private const TTL      = 3600;
	private const LIMIT    = 1048576;

	private const IMAGE = 'assets/anesthesia-cesarean-basics/monitor.png';

	/**
	 * The uploads `basedir` of this test: a fresh folder under the system temp dir.
	 */
	private string $uploads;

	private int $now = self::NOW;

	private bool $can = true;

	private int $wp_max_upload_size = 8388608;

	/**
	 * @var list<stdClass>
	 */
	private array $instructors = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->uploads = sys_get_temp_dir() . '/vl-lms-import-page-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->uploads, 0o755, true );

		$_GET = [];

		$escape = static fn ( string $text ): string => htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'esc_html__' )->alias( $escape );
		Functions\when( 'esc_html' )->alias( $escape );
		Functions\when( 'esc_attr' )->alias( $escape );
		Functions\when( 'esc_url' )->alias( $escape );
		Functions\when( 'wp_unslash' )->returnArg( 1 );
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'admin_url' )->alias( static fn ( string $path = '' ): string => 'https://example.test/wp-admin/' . $path );
		Functions\when( 'wp_nonce_field' )->alias(
			static function ( string $action, string $name ): void {
				echo '<input type="hidden" name="' . $name . '" value="nonce:' . $action . '" />'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Test stub.
			}
		);
		Functions\when( 'submit_button' )->alias(
			static function ( string $text, string $type = 'primary' ): void {
				echo '<input type="submit" class="button button-' . $type . '" value="' . $text . '" />'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Test stub.
			}
		);
		Functions\when( 'size_format' )->alias( static fn ( int $bytes ): string => $bytes . ' B' );
		Functions\when( 'wp_max_upload_size' )->alias( fn (): int => $this->wp_max_upload_size );
		Functions\when( 'number_format_i18n' )->alias( static fn ( float $number, int $decimals = 0 ): string => number_format( $number, $decimals, ',', ' ' ) );
		Functions\when( 'current_user_can' )->alias( fn (): bool => $this->can );
		Functions\when( 'get_current_user_id' )->justReturn( self::ADMIN_ID );
		Functions\when( 'wp_die' )->alias(
			static function (): void {
				throw new RuntimeException( 'wp_die' );
			}
		);
		Functions\when( 'time' )->alias( fn (): int => $this->now );
		Functions\when( 'wp_upload_dir' )->alias(
			fn (): array => [
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
		Functions\when( 'term_exists' )->justReturn(
			[
				'term_id'          => 7,
				'term_taxonomy_id' => 7,
			]
		);
		Functions\when( 'get_users' )->alias( fn (): array => $this->instructors );
		Functions\when( 'get_userdata' )->alias(
			static function ( int $id ): WP_User {
				$user               = new WP_User();
				$user->ID           = $id;
				$user->display_name = 'Адміністратор';
				$user->user_login   = 'admin';

				return $user;
			}
		);
	}

	protected function tearDown(): void {
		$_GET = [];
		Monkey\tearDown();
		$this->remove( $this->uploads );
		parent::tearDown();
	}

	public function test_without_the_capability_the_page_dies(): void {
		$this->can = false;

		ob_start();
		try {
			$this->page()->render();
			self::fail( 'Expected wp_die().' );
		} catch ( RuntimeException $exception ) {
			self::assertSame( 'wp_die', $exception->getMessage() );
		} finally {
			$html = (string) ob_get_clean();
		}

		self::assertSame( '', $html );
	}

	public function test_the_upload_screen_offers_a_course_file_form(): void {
		$html = $this->render();

		self::assertStringContainsString( '<h1>Імпорт курсу</h1>', $html );
		self::assertStringContainsString( '<form method="post" action="https://example.test/wp-admin/admin-post.php" enctype="multipart/form-data">', $html );
		self::assertStringContainsString( '<input type="hidden" name="action" value="vl_lms_import_upload" />', $html );
		self::assertStringContainsString( '<input type="hidden" name="_vl_lms_import_nonce" value="nonce:vl_lms_import_upload" />', $html );
		self::assertStringContainsString( '<input type="file" id="vl-lms-import-file" name="course_file" accept=".md,.zip" required />', $html );
		self::assertStringContainsString( 'Формат файлу курсу v1', $html );
		self::assertStringContainsString( 'value="Завантажити й перевірити"', $html );
		self::assertStringNotContainsString( 'notice', $html );
		self::assertStringNotContainsString( 'vl_lms_import_confirm', $html );
		self::assertStringNotContainsString( 'vl_lms_import_discard', $html );
	}

	/**
	 * @return array<string, array{0: int, 1: string}>
	 */
	public static function upload_limits(): array {
		return [
			'the importer limit is the smaller' => [ 8388608, 'Максимальний розмір файлу: 1048576 B' ],
			'the PHP limit is the smaller'      => [ 524288, 'Максимальний розмір файлу: 524288 B' ],
		];
	}

	/**
	 * @dataProvider upload_limits
	 */
	public function test_the_upload_screen_shows_the_limit_that_applies( int $wp_max_upload_size, string $line ): void {
		$this->wp_max_upload_size = $wp_max_upload_size;

		self::assertStringContainsString( '<p class="description">' . $line . '</p>', $this->render() );
	}

	/**
	 * @return array<string, array{0: array<string, string>, 1: string, 2: callable(): string}>
	 */
	public static function notice_urls(): array {
		return [
			'a refused type'      => [ [ 'error' => IntakeException::WRONG_TYPE ], 'error', static fn (): string => IntakeException::wrong_type()->getMessage() ],
			'a too large archive' => [ [ 'error' => IntakeException::ARCHIVE_TOO_LARGE ], 'error', static fn (): string => IntakeException::archive_too_large( self::LIMIT )->getMessage() ],
			'a foreign token'     => [ [ 'error' => TempStoreException::FOREIGN_TOKEN ], 'error', static fn (): string => TempStoreException::foreign_token()->getMessage() ],
			'an unknown code'     => [ [ 'error' => '<b>Ваш курс видалено</b>' ], 'error', static fn (): string => IntakeException::upload_failed()->getMessage() ],
			'a discarded upload'  => [ [ 'discarded' => '1' ], 'success', static fn (): string => 'Імпорт скасовано, завантажений файл видалено.' ],
			'an expired upload'   => [ [ 'expired' => '1' ], 'warning', static fn (): string => TempStoreException::expired_token()->getMessage() ],
		];
	}

	/**
	 * @dataProvider notice_urls
	 *
	 * @param array<string, string> $query
	 * @param callable(): string    $message
	 */
	public function test_the_upload_screen_shows_the_notice_its_url_names( array $query, string $type, callable $message ): void {
		$html = $this->render( $query );

		self::assertSame( [ [ $type, htmlspecialchars( $message(), ENT_QUOTES, 'UTF-8' ) ] ], $this->notices( $html ) );
		self::assertStringNotContainsString( 'Ваш курс видалено', $html, 'Text from the URL is never printed.' );
		self::assertStringContainsString( 'value="vl_lms_import_upload"', $html );
	}

	public function test_every_render_sweeps_expired_folders(): void {
		$this->now = self::NOW - self::TTL - 60;
		$stale     = $this->store()->create( self::OTHER_ID );
		$this->now = self::NOW;

		$this->render();

		self::assertDirectoryDoesNotExist( $stale->dir );

		$this->now = self::NOW - self::TTL - 60;
		$stale     = $this->store()->create( self::OTHER_ID );
		$this->now = self::NOW;
		$fresh     = $this->upload_folder( 'course-with-modules.md' );

		$this->render( [ 'token' => $fresh->token ] );

		self::assertDirectoryDoesNotExist( $stale->dir );
		self::assertDirectoryExists( $fresh->dir );
	}

	public function test_a_file_with_errors_lists_them_and_offers_no_import(): void {
		$handle   = $this->upload_folder( 'broken/missing-section.md' );
		$expected = [];
		foreach ( $this->service()->analyse( self::FIXTURES . 'broken/missing-section.md' )->issues->all() as $issue ) {
			if ( IssueLevel::ERROR === $issue->level ) {
				$expected[] = [ null === $issue->line ? '—' : (string) $issue->line, htmlspecialchars( $issue->message, ENT_QUOTES, 'UTF-8' ) ];
			}
		}

		$html = $this->render( [ 'token' => $handle->token ] );

		self::assertNotSame( [], $expected );
		self::assertSame( [ [ 'error', 'Файл курсу містить помилки, тому курс не буде створено. Виправте файл і завантажте його знову.' ] ], $this->notices( $html ) );
		self::assertSame( $expected, $this->rows( $html, 2 ) );
		self::assertSame( [ [ 'vl_lms_import_discard', $handle->token, 'Завантажити інший файл' ] ], $this->forms( $html ) );
		self::assertStringNotContainsString( 'vl_lms_import_confirm', $html );
		self::assertStringNotContainsString( 'Імпортувати', $html );
		self::assertStringNotContainsString( 'instructor_id', $html );
		self::assertStringNotContainsString( 'course_file', $html );
	}

	public function test_a_valid_course_shows_what_the_import_creates_and_the_confirm_form(): void {
		$this->instructors = [ $this->row( '12', 'Іваненко Олена', 'olena' ), $this->row( '14', 'Петренко Іван', 'ivan' ) ];
		$handle            = $this->upload_folder( 'course-with-modules.md', self::ADMIN_ID, [ self::IMAGE, 'assets/extra.png' ] );

		$html = $this->render( [ 'token' => $handle->token ] );

		self::assertSame( [], $this->notices( $html ) );
		self::assertSame(
			[
				'Модулів'             => '2',
				'Уроків'              => '3',
				'Тестів'              => '2',
				'Питань'              => '4',
				'Знайдено зображень'  => '1',
				'Відсутніх зображень' => '0',
			],
			$this->cards( $html )
		);
		self::assertSame(
			[
				[ 'Назва', 'Анестезія при кесаревому розтині: базовий курс' ],
				[ 'Slug', 'anesthesia-cesarean-basics' ],
				[ 'Автор матеріалу', 'Іваненко Олена' ],
				[ 'Організація автора', 'Ветеринарна клініка «Лапа»' ],
				[ 'Рівень', 'practitioner → advanced' ],
				[ 'Категорія', 'anesthesia' ],
				[ 'Теги', 'cesarean, anesthesia' ],
				[ 'Тривалість', '1,5 год' ],
				[ 'Прохідний бал тестів', '70 %' ],
				[ 'Статус файлу', 'draft' ],
				[ 'Версія файлу', '1' ],
				[ 'Тип джерела', 'webinar' ],
				[ 'Назва джерела', 'Кесарів розтин. Анестезіологічний супровід' ],
				[ 'Дата джерела', '2025-11-20' ],
			],
			$this->data_rows( $html )
		);

		$warnings = $this->rows( $html, 3 );
		self::assertSame( [ [ '—', 'Попередження' ], [ '37', 'Примітка' ] ], array_map( static fn ( array $row ): array => [ $row[0], $row[1] ], $warnings ) );
		self::assertStringContainsString( '«assets/extra.png»', $warnings[0][2] );

		self::assertSame(
			[
				[ 'vl_lms_import_confirm', $handle->token, 'Імпортувати' ],
				[ 'vl_lms_import_discard', $handle->token, 'Скасувати' ],
			],
			$this->forms( $html )
		);
		self::assertStringContainsString( 'value="nonce:vl_lms_import_confirm"', $html );
		self::assertStringContainsString( 'value="nonce:vl_lms_import_discard"', $html );
		self::assertSame(
			[
				[ '12', true, 'Іваненко Олена (olena)' ],
				[ '14', false, 'Петренко Іван (ivan)' ],
				[ '1', false, 'Адміністратор (admin)' ],
			],
			$this->options( $html )
		);
		self::assertStringContainsString( '<select id="vl-lms-import-instructor" name="instructor_id">', $html );
		self::assertStringContainsString( 'Автор у файлі курсу: Іваненко Олена', $html );
	}

	public function test_a_missing_image_is_counted_and_warned_and_the_admin_is_preselected_without_a_matching_instructor(): void {
		$this->instructors = [ $this->row( '14', 'Петренко Іван', 'ivan' ) ];
		$handle            = $this->upload_folder( 'course-with-modules.md' );

		$html = $this->render( [ 'token' => $handle->token ] );

		$cards = $this->cards( $html );
		self::assertSame( [ '0', '1' ], [ $cards['Знайдено зображень'], $cards['Відсутніх зображень'] ] );

		$warnings = $this->rows( $html, 3 );
		self::assertSame( [ [ '37', 'Примітка' ], [ '71', 'Попередження' ] ], array_map( static fn ( array $row ): array => [ $row[0], $row[1] ], $warnings ) );
		self::assertStringContainsString( '«' . self::IMAGE . '»', $warnings[1][2] );

		self::assertSame(
			[
				[ '14', false, 'Петренко Іван (ivan)' ],
				[ '1', true, 'Адміністратор (admin)' ],
			],
			$this->options( $html )
		);
	}

	public function test_an_expired_token_shows_the_expiry_notice_and_its_folder_is_swept_afterwards(): void {
		$this->now = self::NOW - self::TTL - 60;
		$handle    = $this->upload_folder( 'course-with-modules.md' );
		$this->now = self::NOW;

		$html = $this->render( [ 'token' => $handle->token ] );

		self::assertSame( [ [ 'error', TempStoreException::expired_token()->getMessage() ] ], $this->notices( $html ) );
		self::assertStringContainsString( 'value="vl_lms_import_upload"', $html );
		self::assertStringNotContainsString( 'vl_lms_import_confirm', $html );
		self::assertDirectoryDoesNotExist( $handle->dir );
	}

	public function test_another_user_s_token_shows_the_foreign_token_notice_and_keeps_its_folder(): void {
		$handle = $this->upload_folder( 'course-with-modules.md', self::OTHER_ID );

		$html = $this->render( [ 'token' => $handle->token ] );

		self::assertSame( [ [ 'error', TempStoreException::foreign_token()->getMessage() ] ], $this->notices( $html ) );
		self::assertStringNotContainsString( 'vl_lms_import_confirm', $html );
		self::assertDirectoryExists( $handle->dir );
	}

	public function test_an_unknown_token_shows_the_unknown_token_notice(): void {
		$html = $this->render( [ 'token' => 'fedcba9876543210fedcba9876543210' ] );

		self::assertSame( [ [ 'error', TempStoreException::unknown_token()->getMessage() ] ], $this->notices( $html ) );
		self::assertStringContainsString( 'value="vl_lms_import_upload"', $html );
	}

	/**
	 * @param array<string, string> $query
	 */
	private function render( array $query = [] ): string {
		$_GET = $query;

		ob_start();
		try {
			$this->page()->render();
		} finally {
			$html = (string) ob_get_clean();
		}

		return $html;
	}

	private function page(): ImportPage {
		$config = $this->config();
		$media  = new MediaImporter();

		return new ImportPage( $config, new TempStore( $config ), $this->service(), $media, new InstructorCandidates() );
	}

	private function service(): ImportService {
		$markdown_to_html = new MarkdownToHtml();

		return new ImportService(
			new CourseDocumentParser( new FrontMatterParser(), new QuizBlockParser() ),
			new CourseValidator( $markdown_to_html ),
			new CourseHtmlBuilder( $markdown_to_html ),
			new ModuleHtmlBuilder(),
			new LessonHtmlBuilder( $markdown_to_html ),
			new Importer( new MediaImporter() ),
			70
		);
	}

	private function store(): TempStore {
		return new TempStore( $this->config() );
	}

	private function config(): ImportConfig {
		return new ImportConfig( self::LIMIT, self::TTL, [ 'png', 'jpg', 'jpeg', 'gif', 'webp' ], 70 );
	}

	/**
	 * A token folder as the upload intake leaves it: a fixture as `course.md`, plus images.
	 *
	 * @param list<string> $assets Paths under the folder, e.g. `assets/x.png`.
	 */
	private function upload_folder( string $fixture, int $owner = self::ADMIN_ID, array $assets = [] ): Handle {
		$handle = $this->store()->create( $owner );
		copy( self::FIXTURES . $fixture, $handle->dir . '/course.md' );

		foreach ( $assets as $path ) {
			$folder = dirname( $handle->dir . '/' . $path );
			if ( ! is_dir( $folder ) ) {
				mkdir( $folder, 0o755, true );
			}
			file_put_contents( $handle->dir . '/' . $path, 'png' );
		}

		return $handle;
	}

	/**
	 * A row as `get_users()` returns it for a `fields` list.
	 */
	private function row( string $id, string $display_name, string $user_login ): stdClass {
		$row               = new stdClass();
		$row->ID           = $id;
		$row->display_name = $display_name;
		$row->user_login   = $user_login;

		return $row;
	}

	/**
	 * @return list<array{0: string, 1: string}> Type and text of each notice.
	 */
	private function notices( string $html ): array {
		preg_match_all( '/<div class="notice notice-([a-z]+)"><p>([^<]*)<\/p><\/div>/u', $html, $matches, PREG_SET_ORDER );

		return array_map( static fn ( array $row ): array => [ $row[1], $row[2] ], $matches );
	}

	/**
	 * @return array<string, string> Card label => value.
	 */
	private function cards( string $html ): array {
		preg_match_all( '/<div class="vl-lms-import-card"[^>]*><div[^>]*>([^<]*)<\/div><div[^>]*>([^<]*)<\/div><\/div>/u', $html, $matches );

		return array_combine( $matches[1], $matches[2] );
	}

	/**
	 * @return list<array{0: string, 1: string}> The course data table: label and value.
	 */
	private function data_rows( string $html ): array {
		preg_match_all( '/<tr><th scope="row">([^<]*)<\/th><td>([^<]*)<\/td><\/tr>/u', $html, $matches, PREG_SET_ORDER );

		return array_map( static fn ( array $row ): array => [ $row[1], $row[2] ], $matches );
	}

	/**
	 * @return list<list<string>> The cells of each body row that has exactly `$cells` plain cells.
	 */
	private function rows( string $html, int $cells ): array {
		preg_match_all( '/<tr>' . str_repeat( '<td>([^<]*)<\/td>', $cells ) . '<\/tr>/u', $html, $matches, PREG_SET_ORDER );

		return array_map( static fn ( array $row ): array => array_slice( $row, 1 ), $matches );
	}

	/**
	 * @return list<array{0: string, 1: string, 2: string}> Action, token and button of each form that posts a token.
	 */
	private function forms( string $html ): array {
		preg_match_all(
			'/<form method="post" action="https:\/\/example\.test\/wp-admin\/admin-post\.php"><input type="hidden" name="action" value="([a-z_]+)" \/><input type="hidden" name="token" value="([0-9a-f]*)" \/>.*?value="([^"]*)" \/><\/form>/su',
			$html,
			$matches,
			PREG_SET_ORDER
		);

		return array_map( static fn ( array $row ): array => [ $row[1], $row[2], $row[3] ], $matches );
	}

	/**
	 * @return list<array{0: string, 1: bool, 2: string}> Value, selected state and text of each option.
	 */
	private function options( string $html ): array {
		preg_match_all( '/<option value="(\d+)"( selected="selected")?>([^<]*)<\/option>/u', $html, $matches, PREG_SET_ORDER );

		return array_map( static fn ( array $row ): array => [ $row[1], '' !== $row[2], $row[3] ], $matches );
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
