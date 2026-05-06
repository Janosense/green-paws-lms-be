<?php

declare(strict_types=1);

namespace VL\LMS\Certificate\Pdf;

use Dompdf\Dompdf;
use Dompdf\Options;
use VL\LMS\Domain\Certificate\Certificate;
use VL\LMS\Support\Logger;

/**
 * Lazy disk-cached PDF generator.
 *
 * First call for a certificate renders HTML via
 * {@see CertificateRenderer}, runs it through dompdf, and writes the
 * resulting binary to `wp-content/uploads/certificates/{uuid}.pdf`.
 * Subsequent calls verify the file exists on disk and return the same
 * path without re-rendering.
 *
 * On first directory creation the generator drops two hardening files
 * alongside: `.htaccess` (`Options -Indexes`) and `index.php`
 * (`<?php // Silence is golden.`). Apache directory listing and PHP
 * directory-traversal indexing are both blocked even though the
 * uploads URL is otherwise public — only people with the exact UUID
 * can fetch the binary.
 *
 * @author Tymofii Synianskyi
 */
class PdfGenerator {

	private const string SUBDIR = 'certificates';

	public function __construct(
		private readonly CertificateRenderer $renderer,
		private readonly Logger $logger
	) {
	}

	public function generate( Certificate $certificate ): GeneratedPdf {
		$basedir = $this->upload_basedir();
		$dir     = $basedir . '/' . self::SUBDIR;
		$file    = $certificate->uuid . '.pdf';
		$abs     = $dir . '/' . $file;
		$rel     = self::SUBDIR . '/' . $file;

		if ( $this->file_exists_and_non_empty( $abs ) ) {
			return new GeneratedPdf( $abs, $rel, true );
		}

		$this->ensure_directory( $dir );

		$html = $this->renderer->render( $certificate );

		$dompdf = $this->build_dompdf( $basedir );
		$dompdf->loadHtml( $html, 'UTF-8' );
		$dompdf->setPaper( 'A4', 'landscape' );
		$dompdf->render();

		$bytes = $dompdf->output();
		if ( false === file_put_contents( $abs, $bytes ) ) {
			$this->logger->error(
				'PdfGenerator: failed to write PDF',
				[
					'uuid' => $certificate->uuid,
					'path' => $abs,
				]
			);
			throw new \RuntimeException( 'Failed to write certificate PDF.' );
		}

		return new GeneratedPdf( $abs, $rel, false );
	}

	/**
	 * Resolve the uploads basedir. Wrapped as a `protected` seam so
	 * tests can route to a tmpfs path instead of the real
	 * `wp-content/uploads`.
	 */
	protected function upload_basedir(): string {
		$dir = wp_upload_dir();
		return rtrim( $dir['basedir'], '/' );
	}

	/**
	 * Build the dompdf instance. Isolated so tests can subclass and
	 * stub it out — exercising the actual dompdf binary in unit tests
	 * is brittle and slow.
	 *
	 * Phase 9.7: registers the Onest TTF family bundled under
	 * `src/Pdf/Fonts/` so the certificate template can pick it up.
	 * Cyrillic coverage is preserved by keeping `DejaVu Sans` as the
	 * default font (and as the fallback declared in the template's
	 * `font-family` rule) — if Onest registration fails for any reason,
	 * the certificate still renders.
	 */
	protected function build_dompdf( string $basedir ): Dompdf {
		$options = new Options();
		$options->set( 'isRemoteEnabled', false );
		$options->set( 'defaultFont', 'DejaVu Sans' );
		$options->set( 'chroot', $basedir );
		$options->set( 'isHtml5ParserEnabled', true );

		$dompdf = new Dompdf( $options );

		$this->register_onest_fonts( $dompdf );

		return $dompdf;
	}

	/**
	 * Register the Onest TTFs shipped with the plugin so dompdf can
	 * resolve `font-family: Onest` at render time. Failures are logged
	 * but never raised — DejaVu Sans (template fallback) keeps the
	 * certificate renderable.
	 */
	protected function register_onest_fonts( Dompdf $dompdf ): void {
		$fonts_dir = dirname( __DIR__, 2 ) . '/Pdf/Fonts';
		$regular   = $fonts_dir . '/Onest-Regular.ttf';
		$bold      = $fonts_dir . '/Onest-Bold.ttf';

		if ( ! is_file( $regular ) || ! is_file( $bold ) ) {
			$this->logger->warning(
				'PdfGenerator: Onest TTFs missing — falling back to DejaVu Sans.',
				[ 'fonts_dir' => $fonts_dir ]
			);
			return;
		}

		try {
			$metrics = $dompdf->getFontMetrics();
			$metrics->registerFont(
				[
					'family' => 'Onest',
					'style'  => 'normal',
					'weight' => 'normal',
				],
				$regular
			);
			$metrics->registerFont(
				[
					'family' => 'Onest',
					'style'  => 'normal',
					'weight' => 'bold',
				],
				$bold
			);
		} catch ( \Throwable $e ) {
			$this->logger->warning(
				'PdfGenerator: Onest registration failed — falling back to DejaVu Sans.',
				[ 'message' => $e->getMessage() ]
			);
		}
	}

	protected function file_exists_and_non_empty( string $path ): bool {
		return is_file( $path ) && filesize( $path ) > 0;
	}

	private function ensure_directory( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		$htaccess = $dir . '/.htaccess';
		if ( ! is_file( $htaccess ) ) {
			file_put_contents( $htaccess, "Options -Indexes\n" );
		}
		$index = $dir . '/index.php';
		if ( ! is_file( $index ) ) {
			file_put_contents( $index, "<?php // Silence is golden.\n" );
		}
	}
}
