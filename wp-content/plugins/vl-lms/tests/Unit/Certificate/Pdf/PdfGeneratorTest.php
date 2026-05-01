<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Certificate\Pdf;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Certificate\Pdf\CertificateRenderer;
use VL\LMS\Certificate\Pdf\GeneratedPdf;
use VL\LMS\Certificate\Pdf\PdfGenerator;
use VL\LMS\Domain\Certificate\Certificate;
use VL\LMS\Support\Logger;

final class PdfGeneratorTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface&CertificateRenderer */
	private $renderer;

	/** @var Mockery\MockInterface&Logger */
	private $logger;

	private string $tmp_dir = '';

	private \DateTimeImmutable $now;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->renderer = Mockery::mock( CertificateRenderer::class );
		$this->logger   = Mockery::mock( Logger::class )->shouldIgnoreMissing();
		$this->now      = new \DateTimeImmutable( '2026-04-29T10:00:00+00:00' );

		$this->tmp_dir = sys_get_temp_dir() . '/vl-lms-pdf-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->tmp_dir, 0o755, true );

		Functions\when( 'wp_upload_dir' )->alias( fn (): array => [ 'basedir' => $this->tmp_dir ] );
		Functions\when( 'wp_mkdir_p' )->alias(
			static fn ( string $dir ): bool => is_dir( $dir ) ? true : (bool) mkdir( $dir, 0o755, true )
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		$this->rrmdir( $this->tmp_dir );
		parent::tearDown();
	}

	private function rrmdir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( scandir( $dir ) as $f ) {
			if ( '.' === $f || '..' === $f ) {
				continue;
			}
			$p = $dir . '/' . $f;
			if ( is_dir( $p ) ) {
				$this->rrmdir( $p );
			} else {
				unlink( $p );
			}
		}
		rmdir( $dir );
	}

	private function generator(): PdfGenerator {
		// Anonymous subclass overrides build_dompdf so we never invoke
		// the real engine in tests — too brittle and slow.
		return new class( $this->renderer, $this->logger, $this->tmp_dir ) extends PdfGenerator {

			public function __construct(
				CertificateRenderer $r,
				Logger $l,
				private readonly string $tmp_dir
			) {
				parent::__construct( $r, $l );
			}

			protected function upload_basedir(): string {
				return $this->tmp_dir;
			}

			protected function build_dompdf( string $basedir ): \Dompdf\Dompdf {
				$mock = Mockery::mock( \Dompdf\Dompdf::class );
				$mock->shouldReceive( 'loadHtml' )->andReturnSelf();
				$mock->shouldReceive( 'setPaper' )->andReturnSelf();
				$mock->shouldReceive( 'render' )->andReturnSelf();
				$mock->shouldReceive( 'output' )->andReturn( "%PDF-1.4 fake binary contents\n%%EOF" );
				return $mock;
			}
		};
	}

	private function certificate( string $uuid = 'abc-1234-5678' ): Certificate {
		return new Certificate(
			1,
			$uuid,
			5,
			50,
			21,
			$this->now,
			null,
			null,
			null,
			[ 'course_title' => 'X' ],
			null,
			$this->now,
			$this->now
		);
	}

	public function test_first_call_renders_writes_and_returns_paths(): void {
		$cert = $this->certificate();

		$this->renderer->shouldReceive( 'render' )->once()->andReturn( '<html></html>' );

		$result = $this->generator()->generate( $cert );

		self::assertInstanceOf( GeneratedPdf::class, $result );
		self::assertFalse( $result->cache_hit );
		self::assertSame( 'certificates/abc-1234-5678.pdf', $result->relative_path );
		self::assertFileExists( $result->absolute_path );

		$bytes = file_get_contents( $result->absolute_path );
		self::assertStringStartsWith( '%PDF-1.', (string) $bytes );

		// Hardening files were dropped on first creation.
		self::assertFileExists( $this->tmp_dir . '/certificates/.htaccess' );
		self::assertFileExists( $this->tmp_dir . '/certificates/index.php' );
	}

	public function test_second_call_returns_cache_hit_without_re_rendering(): void {
		$cert = $this->certificate();
		$this->renderer->shouldReceive( 'render' )->once()->andReturn( '<html></html>' );

		$first = $this->generator()->generate( $cert );

		// renderer should NOT be called again; redefine expectation.
		$this->renderer->shouldNotReceive( 'render' );

		$second = $this->generator()->generate( $cert );

		self::assertTrue( $second->cache_hit );
		self::assertSame( $first->absolute_path, $second->absolute_path );
		self::assertSame( $first->relative_path, $second->relative_path );
	}

	public function test_distinct_uuids_yield_distinct_paths(): void {
		$this->renderer->shouldReceive( 'render' )->twice()->andReturn( '<html></html>' );

		$a = $this->generator()->generate( $this->certificate( 'aaaa-1' ) );
		$b = $this->generator()->generate( $this->certificate( 'bbbb-2' ) );

		self::assertNotSame( $a->absolute_path, $b->absolute_path );
		self::assertSame( 'certificates/aaaa-1.pdf', $a->relative_path );
		self::assertSame( 'certificates/bbbb-2.pdf', $b->relative_path );
	}
}
