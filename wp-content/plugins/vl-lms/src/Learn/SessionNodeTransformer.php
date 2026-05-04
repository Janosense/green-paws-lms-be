<?php

declare(strict_types=1);

namespace VL\LMS\Learn;

use VL\LMS\Repositories\SessionAttendanceRepository;
use WP_Post;

/**
 * Curriculum-tree leaf transformer for `vl_session` posts.
 *
 * Sessions appear as siblings to module / lesson nodes at the top level
 * of cohort courses (Phase 7.4). Their `is_completed` field is the single
 * source of truth for "did this user attend / complete this session" —
 * computed from a per-session attendance read since sessions don't appear
 * in `vl_progress`.
 *
 * `ProgressOverlay` is accepted but not consulted, kept symmetric with
 * sibling node transformers so the curriculum-tree walk doesn't branch.
 *
 * Concrete (not final) for DI / testability.
 *
 * @author Tymofii Synianskyi
 */
class SessionNodeTransformer {

	public function __construct(
		private readonly SessionAttendanceRepository $attendance,
	) {
	}

	/**
	 * @return array{
	 *     type: string,
	 *     id: int,
	 *     slug: string,
	 *     title: string,
	 *     session_number: int,
	 *     scheduled_start: string|null,
	 *     scheduled_end: string|null,
	 *     status: string,
	 *     is_completed: bool,
	 *     join_url_path: string,
	 *     recording_url_path: string|null
	 * }
	 */
	public function transform( WP_Post $session, int $user_id, ProgressOverlay $overlay ): array {
		// $overlay is unused — sessions live outside vl_progress. The param
		// stays so CurriculumTransformer can dispatch by node type without
		// signature branching.
		unset( $overlay );

		$session_id     = (int) $session->ID;
		$slug           = (string) $session->post_name;
		$attendance     = $this->attendance->list_for_user( $user_id, $session_id );
		$status         = (string) get_post_meta( $session_id, '_vl_session_status', true );
		$session_number = (int) get_post_meta( $session_id, '_vl_session_number', true );
		$start_raw      = (string) get_post_meta( $session_id, '_vl_session_scheduled_start', true );
		$end_raw        = (string) get_post_meta( $session_id, '_vl_session_scheduled_end', true );
		$recording_url  = (string) get_post_meta( $session_id, '_vl_session_recording_url', true );

		return [
			'type'               => 'session',
			'id'                 => $session_id,
			'slug'               => $slug,
			'title'              => $this->title( $session ),
			'session_number'     => $session_number,
			'scheduled_start'    => '' === $start_raw ? null : $start_raw,
			'scheduled_end'      => '' === $end_raw ? null : $end_raw,
			'status'             => '' === $status ? 'scheduled' : $status,
			'is_completed'       => count( $attendance ) > 0,
			'join_url_path'      => '/vl/v1/learn/sessions/' . $slug . '/join',
			'recording_url_path' => '' === $recording_url ? null : '/vl/v1/learn/sessions/' . $slug . '/recording',
		];
	}

	private function title( WP_Post $session ): string {
		$title = get_the_title( $session );
		return wp_strip_all_tags( $title );
	}
}
