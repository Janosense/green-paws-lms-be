<?php

declare(strict_types=1);

namespace VL\LMS\Admin\Quizzes;

use WP_Post;

/**
 * AJAX backend for the "pick an existing quiz" feature shared by the
 * course / module / lesson / session edit screens
 * ({@see \VL\LMS\Admin\MetaBoxes\ChildList\QuizListMetaBox}).
 *
 * A quiz attaches flexibly under a `vl_course`, `vl_module`, `vl_lesson`, or
 * `vl_session`, so `attach` accepts any of those parent types via a single
 * `parent_id` field. Otherwise this mirrors
 * {@see \VL\LMS\Admin\Lessons\LessonPickerAjaxHandler} exactly.
 *
 * Three verbs, each on its own nonce action:
 *  - `search`  → list unattached (`post_parent = 0`) `vl_quiz` posts matching
 *                a title query, so attaching one never steals it from
 *                another parent.
 *  - `attach`  → re-parent an unattached quiz and place it at the end of the
 *                list (`menu_order = max + 1`).
 *  - `detach`  → unlink a quiz (`post_parent = 0`) without deleting it.
 *
 * Not declared `final` — the unit tests subclass for behaviour
 * verification with Mockery.
 *
 * @author Tymofii Synianskyi
 */
class QuizPickerAjaxHandler {

	public const string SEARCH_ACTION = 'vl_lms_quiz_search';
	public const string ATTACH_ACTION = 'vl_lms_quiz_attach';
	public const string DETACH_ACTION = 'vl_lms_quiz_detach';

	private const int MAX_RESULTS = 10;

	/** @var list<string> */
	private const array PARENT_TYPES = [ 'vl_course', 'vl_module', 'vl_lesson', 'vl_session' ];

	public function search(): void {
		$this->require_nonce( self::SEARCH_ACTION );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified above.
		$query = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['q'] ) ) : '';
		$query = trim( $query );
		if ( '' === $query ) {
			wp_send_json_success( [] );
		}

		$quizzes = get_posts(
			[
				'post_type'      => 'vl_quiz',
				'post_parent'    => 0,
				'post_status'    => 'any',
				's'              => $query,
				'posts_per_page' => self::MAX_RESULTS,
				'orderby'        => 'title',
				'order'          => 'ASC',
			]
		);

		$payload = [];
		if ( is_array( $quizzes ) ) {
			foreach ( $quizzes as $quiz ) {
				if ( ! $quiz instanceof WP_Post ) {
					continue;
				}
				$title     = '' !== $quiz->post_title ? $quiz->post_title : sprintf( '#%d', (int) $quiz->ID );
				$payload[] = [
					'id'    => (int) $quiz->ID,
					'title' => $title,
				];
			}
		}

		wp_send_json_success( $payload );
	}

	public function attach(): void {
		$this->require_nonce( self::ATTACH_ACTION );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$parent_id = isset( $_POST['parent_id'] ) ? absint( wp_unslash( $_POST['parent_id'] ) ) : 0;
		$quiz_id   = isset( $_POST['quiz_id'] ) ? absint( wp_unslash( $_POST['quiz_id'] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( $parent_id <= 0 || $quiz_id <= 0 ) {
			wp_send_json_error( [ 'message' => 'missing_ids' ], 400 );
		}

		if ( ! in_array( (string) get_post_type( $parent_id ), self::PARENT_TYPES, true ) ) {
			wp_send_json_error( [ 'message' => 'invalid_parent' ], 400 );
		}

		$quiz = get_post( $quiz_id );
		if ( ! $quiz instanceof WP_Post || 'vl_quiz' !== $quiz->post_type ) {
			wp_send_json_error( [ 'message' => 'invalid_quiz' ], 400 );
		}

		// Honour the "unattached only" contract — never silently move a quiz
		// away from a parent it already belongs to.
		if ( 0 !== (int) $quiz->post_parent ) {
			wp_send_json_error( [ 'message' => 'already_attached' ], 409 );
		}

		if ( ! current_user_can( 'edit_post', $quiz_id ) || ! current_user_can( 'edit_post', $parent_id ) ) {
			wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
		}

		wp_update_post(
			[
				'ID'          => $quiz_id,
				'post_parent' => $parent_id,
				'menu_order'  => $this->next_menu_order( $parent_id ),
			]
		);

		$title = '' !== $quiz->post_title ? $quiz->post_title : sprintf( '#%d', $quiz_id );
		wp_send_json_success(
			[
				'id'       => $quiz_id,
				'title'    => $title,
				'editLink' => (string) get_edit_post_link( $quiz_id, 'raw' ),
			]
		);
	}

	public function detach(): void {
		$this->require_nonce( self::DETACH_ACTION );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$quiz_id = isset( $_POST['quiz_id'] ) ? absint( wp_unslash( $_POST['quiz_id'] ) ) : 0;
		if ( $quiz_id <= 0 ) {
			wp_send_json_error( [ 'message' => 'missing_ids' ], 400 );
		}

		if ( 'vl_quiz' !== get_post_type( $quiz_id ) ) {
			wp_send_json_error( [ 'message' => 'invalid_quiz' ], 400 );
		}

		if ( ! current_user_can( 'edit_post', $quiz_id ) ) {
			wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
		}

		wp_update_post(
			[
				'ID'          => $quiz_id,
				'post_parent' => 0,
			]
		);

		wp_send_json_success( [ 'id' => $quiz_id ] );
	}

	/**
	 * Verify the request nonce for `$action`, sent as `nonce` in the
	 * request body/query. Sends a 403 and halts on failure.
	 */
	private function require_nonce( string $action ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This IS the nonce check.
		$nonce = isset( $_REQUEST['nonce'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, $action ) ) {
			wp_send_json_error( [ 'message' => 'invalid_nonce' ], 403 );
		}
	}

	/**
	 * Next `menu_order` for a parent's quiz list — one past the current
	 * maximum so an attached quiz lands at the bottom.
	 */
	private function next_menu_order( int $parent_id ): int {
		$siblings = get_posts(
			[
				'post_type'      => 'vl_quiz',
				'post_parent'    => $parent_id,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'orderby'        => 'menu_order',
				'order'          => 'DESC',
			]
		);

		if ( is_array( $siblings ) && isset( $siblings[0] ) && $siblings[0] instanceof WP_Post ) {
			return (int) $siblings[0]->menu_order + 1;
		}

		return 0;
	}
}
