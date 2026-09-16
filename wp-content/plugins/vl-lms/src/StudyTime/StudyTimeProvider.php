<?php

declare(strict_types=1);

namespace VL\LMS\StudyTime;

use VL\LMS\Admin\StudyTime\StudentDetailSection;
use VL\LMS\Auth\RestAuthenticator;
use VL\LMS\Learn\EntityHierarchy;
use VL\LMS\Learn\Progression\CurriculumOrder;
use VL\LMS\Services\Enrollment\EnrollmentService;
use VL\LMS\StudyTime\Api\StudyTimeController;
use VL\LMS\StudyTime\Reports\StudyTimeReportQuery;
use VL\LMS\StudyTime\Repositories\StudyTimeRepository;
use VL\LMS\StudyTime\Services\HeartbeatService;
use VL\LMS\Support\Logger;

/**
 * The bootstrap of the `study-time` feature: one registration in
 * `Plugin::build_container()` and one `boot()` call in `Plugin::boot()`
 * (`docs/DECISIONS.md` 2026-09-15 — code location;
 * `wp-content/plugins/vl-lms/CLAUDE.md` → Feature isolation).
 *
 * `boot()` builds nothing: it runs at `plugins_loaded` @ 20, before a theme
 * can add its `vl_lms/study_time/*` filters, so the feature's services — the
 * configuration first of all — are built on first use, inside
 * `rest_api_init`. The `core` collaborators arrive through the one container
 * factory; everything the feature owns (its repository, its heartbeat
 * service) is built here. Sprint 2's wp-admin report sections hook the
 * `vl_lms_admin_*` extension actions from the same place.
 *
 * @author Tymofii Synianskyi
 */
final class StudyTimeProvider {

	private ?StudyTimeConfig $config = null;

	private ?StudyTimeRepository $ledger = null;

	private ?HeartbeatService $heartbeats = null;

	private ?StudyTimeController $controller = null;

	private ?StudyTimeReportQuery $reports = null;

	private ?StudentDetailSection $student_detail_section = null;

	public function __construct(
		private readonly RestAuthenticator $authenticator,
		private readonly EntityHierarchy $hierarchy,
		private readonly EnrollmentService $enrollments,
		private readonly CurriculumOrder $order,
		private readonly Logger $logger
	) {
	}

	public function boot(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
		add_action( 'vl_lms_admin_student_detail_sections', [ $this, 'render_student_detail_section' ], 10, 2 );
	}

	/**
	 * Listens to `core`'s student-card extension point (`docs/CONTRACTS.md`
	 * → wp-admin extension points). Hooked unconditionally: the action only
	 * ever fires while that page renders, and the section — with its report
	 * query and the curriculum walk behind it — is built on first use.
	 *
	 * @param list<\VL\LMS\Domain\Enrollment\Enrollment> $enrollments
	 */
	public function render_student_detail_section( int $user_id, array $enrollments ): void {
		$this->student_detail_section()->render( $user_id, $enrollments );
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
			$this->config(),
			$this->heartbeats(),
			$this->logger
		);
	}

	private function heartbeats(): HeartbeatService {
		return $this->heartbeats ??= new HeartbeatService(
			$this->hierarchy,
			$this->enrollments,
			$this->ledger(),
			$this->config()
		);
	}

	private function student_detail_section(): StudentDetailSection {
		return $this->student_detail_section ??= new StudentDetailSection( $this->reports() );
	}

	private function reports(): StudyTimeReportQuery {
		return $this->reports ??= new StudyTimeReportQuery( $this->order );
	}

	private function ledger(): StudyTimeRepository {
		return $this->ledger ??= new StudyTimeRepository();
	}

	private function config(): StudyTimeConfig {
		return $this->config ??= StudyTimeConfig::from_filters();
	}
}
