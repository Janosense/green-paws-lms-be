<?php

declare(strict_types=1);

namespace VL\LMS\Learn;

use VL\LMS\Learn\Progression\LockMap;
use VL\LMS\Repositories\SessionAttendanceRepository;
use VL\LMS\Support\PlainText;
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
		private readonly QuizNodeTransformer $quiz_transformer
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
	 *     recording_url_path: string|null,
	 *     lock: null,
	 *     quizzes: list<array<string, mixed>>
	 * }
	 */
	public function transform(
		WP_Post $session,
		int $user_id,
		ProgressOverlay $overlay,
		QuizStatusOverlay $quiz_overlay,
		LockMap $locks
	): array {
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
			// Always null: a cohort session is a scheduled calendar event with
			// its own join window, and locking one behind a quiz would strand a
			// learner outside a call that will not be repeated. Emitted anyway
			// so every curriculum leaf has the same shape. Its *quizzes* are
			// lockable — the exemption covers the session itself only.
			'lock'               => null,
			'quizzes'            => $this->quiz_transformer->transform_children( $session_id, $quiz_overlay, $locks ),
		];
	}

	private function title( WP_Post $session ): string {
		return PlainText::from_html( (string) get_the_title( $session ) );
	}
}
