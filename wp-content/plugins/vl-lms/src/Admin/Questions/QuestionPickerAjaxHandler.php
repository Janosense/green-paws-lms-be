<?php

declare(strict_types=1);

namespace VL\LMS\Admin\Questions;

use WP_Post;

/**
 * AJAX backend for the "pick an existing question" feature on the quiz
 * edit screen ({@see \VL\LMS\Admin\MetaBoxes\ChildList\QuestionListMetaBox}).
 *
 * Questions attach to a single parent type — `vl_quiz` — so `attach`
 * validates the `parent_id` is a quiz. Otherwise this mirrors
 * {@see \VL\LMS\Admin\Topics\TopicPickerAjaxHandler} exactly.
 *
 * Three verbs, each on its own nonce action:
 *  - `search`  → list unattached (`post_parent = 0`) `vl_quiz_question`
 *                posts matching a title query, so attaching one never
 *                steals it from another quiz.
 *  - `attach`  → re-parent an unattached question to a quiz and place it
 *                at the end of the list (`menu_order = max + 1`).
 *  - `detach`  → unlink a question (`post_parent = 0`) without deleting it.
 *
 * Not declared `final` — the unit tests subclass for behaviour
 * verification with Mockery.
 *
 * @author Tymofii Synianskyi
 */
class QuestionPickerAjaxHandler {

	public const string SEARCH_ACTION = 'vl_lms_question_search';
	public const string ATTACH_ACTION = 'vl_lms_question_attach';
	public const string DETACH_ACTION = 'vl_lms_question_detach';

	private const int MAX_RESULTS = 10;

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

		$questions = get_posts(
			[
				'post_type'      => 'vl_quiz_question',
				'post_parent'    => 0,
				'post_status'    => 'any',
				's'              => $query,
				'posts_per_page' => self::MAX_RESULTS,
				'orderby'        => 'title',
				'order'          => 'ASC',
			]
		);

		$payload = [];
		if ( is_array( $questions ) ) {
			foreach ( $questions as $question ) {
				if ( ! $question instanceof WP_Post ) {
					continue;
				}
				$title     = '' !== $question->post_title ? $question->post_title : sprintf( '#%d', (int) $question->ID );
				$payload[] = [
					'id'    => (int) $question->ID,
					'title' => $title,
				];
			}
		}

		wp_send_json_success( $payload );
	}

	public function attach(): void {
		$this->require_nonce( self::ATTACH_ACTION );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$parent_id   = isset( $_POST['parent_id'] ) ? absint( wp_unslash( $_POST['parent_id'] ) ) : 0;
		$question_id = isset( $_POST['question_id'] ) ? absint( wp_unslash( $_POST['question_id'] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( $parent_id <= 0 || $question_id <= 0 ) {
			wp_send_json_error( [ 'message' => 'missing_ids' ], 400 );
		}

		if ( 'vl_quiz' !== get_post_type( $parent_id ) ) {
			wp_send_json_error( [ 'message' => 'invalid_parent' ], 400 );
		}

		$question = get_post( $question_id );
		if ( ! $question instanceof WP_Post || 'vl_quiz_question' !== $question->post_type ) {
			wp_send_json_error( [ 'message' => 'invalid_question' ], 400 );
		}

		// Honour the "unattached only" contract — never silently move a
		// question away from a quiz it already belongs to.
		if ( 0 !== (int) $question->post_parent ) {
			wp_send_json_error( [ 'message' => 'already_attached' ], 409 );
		}

		if ( ! current_user_can( 'edit_post', $question_id ) || ! current_user_can( 'edit_post', $parent_id ) ) {
			wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
		}

		wp_update_post(
			[
				'ID'          => $question_id,
				'post_parent' => $parent_id,
				'menu_order'  => $this->next_menu_order( $parent_id ),
			]
		);

		$title = '' !== $question->post_title ? $question->post_title : sprintf( '#%d', $question_id );
		wp_send_json_success(
			[
				'id'       => $question_id,
				'title'    => $title,
				'editLink' => (string) get_edit_post_link( $question_id, 'raw' ),
			]
		);
	}

	public function detach(): void {
		$this->require_nonce( self::DETACH_ACTION );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$question_id = isset( $_POST['question_id'] ) ? absint( wp_unslash( $_POST['question_id'] ) ) : 0;
		if ( $question_id <= 0 ) {
			wp_send_json_error( [ 'message' => 'missing_ids' ], 400 );
		}

		if ( 'vl_quiz_question' !== get_post_type( $question_id ) ) {
			wp_send_json_error( [ 'message' => 'invalid_question' ], 400 );
		}

		if ( ! current_user_can( 'edit_post', $question_id ) ) {
			wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
		}

		wp_update_post(
			[
				'ID'          => $question_id,
				'post_parent' => 0,
			]
		);

		wp_send_json_success( [ 'id' => $question_id ] );
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
	 * Next `menu_order` for a quiz's question list — one past the current
	 * maximum so an attached question lands at the bottom.
	 */
	private function next_menu_order( int $parent_id ): int {
		$siblings = get_posts(
			[
				'post_type'      => 'vl_quiz_question',
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
