<?php

declare(strict_types=1);

namespace VL\LMS\Certificate\Pdf;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/**
 * Tiny wrapper over `chillerlan/php-qrcode` that returns a base64
 * data-URI suitable for embedding directly in the certificate template.
 *
 * Defaults are tuned for the print case: error-correction level Q
 * (~25% recovery — comfortable margin against printer wear and dompdf's
 * lossless raster scaling), scale 6 (≈150×150 px at default 25-cell
 * QR), black-on-white, no logo overlay.
 *
 * @author Tymofii Synianskyi
 */
class QrCodeGenerator {

	/**
	 * Generate a `data:image/png;base64,…` URI for `$url`.
	 */
	public function generate_for_url( string $url ): string {
		$options = new QROptions(
			[
				'outputType'  => QRCode::OUTPUT_IMAGE_PNG,
				'eccLevel'    => EccLevel::Q,
				'scale'       => 6,
				'imageBase64' => true,
			]
		);
		$qr      = new QRCode( $options );
		$out     = $qr->render( $url );

		// chillerlan returns the full data URI when imageBase64 is true.
		return is_string( $out ) ? $out : '';
	}
}
