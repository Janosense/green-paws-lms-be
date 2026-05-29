<?php

declare(strict_types=1);

namespace VL\LMS\Admin\Assignments;

use WP_Post;

/**
 * AJAX backend for the "pick an existing assignment" feature shared by the
 * course / module / lesson / session edit screens
 * ({@see \VL\LMS\Admin\MetaBoxes\ChildList\AssignmentListMetaBox}).
 *
 * An assignment attaches flexibly under a `vl_course`, `vl_module`,
 * `vl_lesson`, or `vl_session`, so `attach` accepts any of those parent
 * types via a single `parent_id` field. Mirrors
 * {@see \VL\LMS\Admin\Quizzes\QuizPickerAjaxHandler}.
 *
 * Three verbs, each on its own nonce action:
 *  - `search`  → list unattached (`post_parent = 0`) `vl_assignment` posts
 *                matching a title query.
 *  - `attach`  → re-parent an unattached assignment to the end of the list
 *                (`menu_order = max + 1`).
 *  - `detach`  → unlink an assignment (`post_parent = 0`) without deleting.
 *
 * Not declared `final` — the unit tests subclass for behaviour
 * verification with Mockery.
 *
 * @author Tymofii Synianskyi
 */
class AssignmentPickerAjaxHandler {

	public const string SEARCH_ACTION = 'vl_lms_assignment_search';
	public const string ATTACH_ACTION = 'vl_lms_assignment_attach';
	public const string DETACH_ACTION = 'vl_lms_assignment_detach';

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

		$assignments = get_posts(
			[
				'post_type'      => 'vl_assignment',
				'post_parent'    => 0,
				'post_status'    => 'any',
				's'              => $query,
				'posts_per_page' => self::MAX_RESULTS,
				'orderby'        => 'title',
				'order'          => 'ASC',
			]
		);

		$payload = [];
		if ( is_array( $assignments ) ) {
			foreach ( $assignments as $assignment ) {
				if ( ! $assignment instanceof WP_Post ) {
					continue;
				}
				$title     = '' !== $assignment->post_title ? $assignment->post_title : sprintf( '#%d', (int) $assignment->ID );
				$payload[] = [
					'id'    => (int) $assignment->ID,
					'title' => $title,
				];
			}
		}

		wp_send_json_success( $payload );
	}

	public function attach(): void {
		$this->require_nonce( self::ATTACH_ACTION );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$parent_id     = isset( $_POST['parent_id'] ) ? absint( wp_unslash( $_POST['parent_id'] ) ) : 0;
		$assignment_id = isset( $_POST['assignment_id'] ) ? absint( wp_unslash( $_POST['assignment_id'] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( $parent_id <= 0 || $assignment_id <= 0 ) {
			wp_send_json_error( [ 'message' => 'missing_ids' ], 400 );
		}

		if ( ! in_array( (string) get_post_type( $parent_id ), self::PARENT_TYPES, true ) ) {
			wp_send_json_error( [ 'message' => 'invalid_parent' ], 400 );
		}

		$assignment = get_post( $assignment_id );
		if ( ! $assignment instanceof WP_Post || 'vl_assignment' !== $assignment->post_type ) {
			wp_send_json_error( [ 'message' => 'invalid_assignment' ], 400 );
		}

		// Honour the "unattached only" contract — never silently move an
		// assignment away from a parent it already belongs to.
		if ( 0 !== (int) $assignment->post_parent ) {
			wp_send_json_error( [ 'message' => 'already_attached' ], 409 );
		}

		if ( ! current_user_can( 'edit_post', $assignment_id ) || ! current_user_can( 'edit_post', $parent_id ) ) {
			wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
		}

		wp_update_post(
			[
				'ID'          => $assignment_id,
				'post_parent' => $parent_id,
				'menu_order'  => $this->next_menu_order( $parent_id ),
			]
		);

		$title = '' !== $assignment->post_title ? $assignment->post_title : sprintf( '#%d', $assignment_id );
		wp_send_json_success(
			[
				'id'       => $assignment_id,
				'title'    => $title,
				'editLink' => (string) get_edit_post_link( $assignment_id, 'raw' ),
			]
		);
	}

	public function detach(): void {
		$this->require_nonce( self::DETACH_ACTION );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$assignment_id = isset( $_POST['assignment_id'] ) ? absint( wp_unslash( $_POST['assignment_id'] ) ) : 0;
		if ( $assignment_id <= 0 ) {
			wp_send_json_error( [ 'message' => 'missing_ids' ], 400 );
		}

		if ( 'vl_assignment' !== get_post_type( $assignment_id ) ) {
			wp_send_json_error( [ 'message' => 'invalid_assignment' ], 400 );
		}

		if ( ! current_user_can( 'edit_post', $assignment_id ) ) {
			wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
		}

		wp_update_post(
			[
				'ID'          => $assignment_id,
				'post_parent' => 0,
			]
		);

		wp_send_json_success( [ 'id' => $assignment_id ] );
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
	 * Next `menu_order` for a parent's assignment list — one past the current
	 * maximum so an attached assignment lands at the bottom.
	 */
	private function next_menu_order( int $parent_id ): int {
		$siblings = get_posts(
			[
				'post_type'      => 'vl_assignment',
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
