<?php

declare(strict_types=1);

namespace VL\LMS\Support;

/**
 * Asset enqueueing stub.
 *
 * Phase 0 has no frontend scripts or styles to enqueue. Later phases can
 * register admin UI assets or block-editor assets through this class.
 */
final class Assets {

	public function register(): void {
		// Phase 1+: wp_enqueue_script / wp_enqueue_style registrations go here.
	}
}
