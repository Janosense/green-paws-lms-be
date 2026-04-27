<?php

declare(strict_types=1);

namespace VL\LMS\Support;

/**
 * Registers the `vl_hero` image size used by course / webinar landing pages.
 *
 * 1920×720 hard crop — a 16:6 widescreen banner that matches the design
 * system's hero block. Hooked from {@see \VL\LMS\Plugin::boot()} on
 * `after_setup_theme`, the canonical hook for `add_image_size`.
 *
 * Existing attachments do not auto-regenerate when a new size is added.
 * On upgrade, admins should run:
 *   `ddev wp media regenerate --image_size=vl_hero --yes`
 * to back-fill the new size on previously uploaded covers. The
 * {@see \VL\LMS\Catalog\Transformers\CoverImageTransformer} omits the
 * `hero` key for attachments that don't yet have the size, so the
 * upgrade is non-blocking.
 *
 * @author Tymofii Synianskyi
 */
final class HeroImageSize {

	public const SIZE_NAME = 'vl_hero';
	public const WIDTH     = 1920;
	public const HEIGHT    = 720;

	public function register(): void {
		add_image_size( self::SIZE_NAME, self::WIDTH, self::HEIGHT, true );
	}
}
