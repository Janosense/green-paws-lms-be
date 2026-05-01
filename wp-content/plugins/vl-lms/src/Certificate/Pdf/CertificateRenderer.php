<?php

declare(strict_types=1);

namespace VL\LMS\Certificate\Pdf;

use VL\LMS\Domain\Certificate\Certificate;
use VL\LMS\Support\Logger;

/**
 * Renders a {@see Certificate} into the HTML+CSS string consumed by
 * dompdf.
 *
 * The renderer captures `templates/certificates/v1.html.php` after
 * extracting the snapshot data and computed display strings into local
 * variables. The template itself is content (HTML+CSS); this class is
 * the thin glue between the snapshot and that file.
 *
 * The verification URL is derived from `vl_lms_frontend_url` (Phase
 * 6.4 will own the public verification page) with a fallback to
 * `home_url()` so the QR target is never empty in development.
 *
 * @author Tymofii Synianskyi
 */
class CertificateRenderer {

	public function __construct(
		private readonly QrCodeGenerator $qr,
		private readonly Logger $logger
	) {
	}

	public function render( Certificate $certificate ): string {
		$snapshot = $certificate->snapshot_data;

		$verification_url = $this->verification_url( $certificate->uuid );
		$qr_data_uri      = $this->qr->generate_for_url( $verification_url );

		$course_title        = isset( $snapshot['course_title'] ) ? (string) $snapshot['course_title'] : '';
		$learner_full_name   = isset( $snapshot['learner_full_name'] ) ? (string) $snapshot['learner_full_name'] : '';
		$instructor_names    = $this->normalise_string_list( $snapshot['instructor_names'] ?? [] );
		$issuer_name         = isset( $snapshot['issuer_name'] ) ? (string) $snapshot['issuer_name'] : 'Green Paws LMS';
		$final_score_pct     = isset( $snapshot['final_score_pct'] ) && null !== $snapshot['final_score_pct']
			? (int) $snapshot['final_score_pct']
			: null;
		$template_version    = isset( $snapshot['template_version'] ) ? (string) $snapshot['template_version'] : 'v1';
		$issued_at_formatted = $this->format_issued_at( $certificate->issued_at );
		$verification_uuid   = $certificate->uuid;

		ob_start();
		try {
			require $this->template_path( $template_version );
		} catch ( \Throwable $e ) {
			ob_end_clean();
			$this->logger->error(
				'CertificateRenderer: template render failed',
				[
					'uuid'  => $certificate->uuid,
					'error' => $e->getMessage(),
				]
			);
			throw $e;
		}
		$html = (string) ob_get_clean();
		return $html;
	}

	private function verification_url( string $uuid ): string {
		$frontend = get_option( 'vl_lms_frontend_url', '' );
		$base     = is_string( $frontend ) && '' !== $frontend
			? rtrim( $frontend, '/' )
			: rtrim( (string) home_url(), '/' );
		return $base . '/certificates/' . $uuid;
	}

	private function format_issued_at( \DateTimeImmutable $dt ): string {
		// `wp_date` honours the site locale; we rely on the i18n module
		// being loaded with `uk_UA` for the demo. A "29 квітня 2026"
		// rendering requires the locale's translated month names.
		if ( function_exists( 'wp_date' ) ) {
			$formatted = wp_date( 'j F Y', $dt->getTimestamp() );
			if ( is_string( $formatted ) && '' !== $formatted ) {
				return $formatted;
			}
		}
		return $dt->format( 'j F Y' );
	}

	private function template_path( string $version ): string {
		// `dirname(__DIR__, 3)` resolves to the plugin root; the
		// templates directory is a sibling of `src/`.
		$root = dirname( __DIR__, 3 );
		return $root . '/templates/certificates/' . $version . '.html.php';
	}

	/**
	 * @param mixed $raw
	 * @return list<string>
	 */
	private function normalise_string_list( mixed $raw ): array {
		if ( ! is_array( $raw ) ) {
			return [];
		}
		$out = [];
		foreach ( $raw as $item ) {
			if ( is_string( $item ) && '' !== $item ) {
				$out[] = $item;
			}
		}
		return $out;
	}
}
