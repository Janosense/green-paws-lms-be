<?php

declare(strict_types=1);

namespace VL\LMS\StudyTime;

use VL\LMS\Auth\RestAuthenticator;
use VL\LMS\StudyTime\Api\StudyTimeController;

/**
 * The bootstrap of the `study-time` feature: one registration in
 * `Plugin::build_container()` and one `boot()` call in `Plugin::boot()`
 * (`docs/DECISIONS.md` 2026-09-15 — code location;
 * `wp-content/plugins/vl-lms/CLAUDE.md` → Feature isolation).
 *
 * `boot()` builds nothing: it runs at `plugins_loaded` @ 20, before a theme
 * can add its `vl_lms/study_time/*` filters, so the feature's services — the
 * configuration first of all — are built on first use, inside
 * `rest_api_init`. Later steps hang off the same lazy graph and the same
 * single container factory: the heartbeat service and its endpoint (Sprint 1
 * Step 4), and the wp-admin report sections (Sprint 2), which hook the
 * `vl_lms_admin_*` extension actions from here.
 *
 * @author Tymofii Synianskyi
 */
final class StudyTimeProvider {

	private ?StudyTimeConfig $config = null;

	private ?StudyTimeController $controller = null;

	public function __construct(
		private readonly RestAuthenticator $authenticator
	) {
	}

	public function boot(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Registers the feature's own `vl/v1/study-time/*` routes. `core`'s
	 * `Plugin::register_rest_routes()` knows nothing about them.
	 */
	public function register_routes(): void {
		$this->controller()->register_routes();
	}

	private function controller(): StudyTimeController {
		return $this->controller ??= new StudyTimeController(
			VL_LMS_API_NAMESPACE,
			$this->authenticator,
			$this->config()
		);
	}

	private function config(): StudyTimeConfig {
		return $this->config ??= StudyTimeConfig::from_filters();
	}
}
