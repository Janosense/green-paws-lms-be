<?php

declare(strict_types=1);

namespace VL\LMS\Admin\Lessons;

use WP_Post;

/**
 * AJAX backend for the "pick an existing lesson" feature shared by the
 * course edit screen ({@see \VL\LMS\Admin\MetaBoxes\ChildList\CourseLessonListMetaBox})
 * and the module edit screen ({@see \VL\LMS\Admin\MetaBoxes\ChildList\LessonListMetaBox}).
 *
 * A lesson can hang directly off a `vl_course` (course-direct, see
 * {@see \VL\LMS\Learn\CurriculumTransformer::query_orphan_lessons()}) or
 * off a `vl_module`, so `attach` accepts either parent type via a single
 * `parent_id` field.
 *
 * Three verbs, each on its own nonce action:
 *  - `search`  → list unattached (`post_parent = 0`) `vl_lesson` posts
 *                matching a title query, so attaching one never steals it
 *                from another course or module.
 *  - `attach`  → re-parent an unattached lesson to a course or module and
 *                place it at the end of the list (`menu_order = max + 1`).
 *  - `detach`  → unlink a lesson (`post_parent = 0`) without deleting it.
 *
 * Mirrors {@see \VL\LMS\Admin\Modules\ModulePickerAjaxHandler}: explicit
 * nonce check, per-post `current_user_can( 'edit_post', … )`,
 * `wp_send_json_*`.
 *
 * Not declared `final` — the unit tests subclass for behaviour
 * verification with Mockery.
 *
 * @author Tymofii Synianskyi
 */
class LessonPickerAjaxHandler {

	public const string SEARCH_ACTION = 'vl_lms_lesson_search';
	public const string ATTACH_ACTION = 'vl_lms_lesson_attach';
	public const string DETACH_ACTION = 'vl_lms_lesson_detach';

	private const int MAX_RESULTS = 10;

	/** @var list<string> */
	private const array PARENT_TYPES = [ 'vl_course', 'vl_module' ];

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

		$lessons = get_posts(
			[
				'post_type'      => 'vl_lesson',
				'post_parent'    => 0,
				'post_status'    => 'any',
				's'              => $query,
				'posts_per_page' => self::MAX_RESULTS,
				'orderby'        => 'title',
				'order'          => 'ASC',
			]
		);

		$payload = [];
		if ( is_array( $lessons ) ) {
			foreach ( $lessons as $lesson ) {
				if ( ! $lesson instanceof WP_Post ) {
					continue;
				}
				$title     = '' !== $lesson->post_title ? $lesson->post_title : sprintf( '#%d', (int) $lesson->ID );
				$payload[] = [
					'id'    => (int) $lesson->ID,
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
		$lesson_id = isset( $_POST['lesson_id'] ) ? absint( wp_unslash( $_POST['lesson_id'] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( $parent_id <= 0 || $lesson_id <= 0 ) {
			wp_send_json_error( [ 'message' => 'missing_ids' ], 400 );
		}

		if ( ! in_array( (string) get_post_type( $parent_id ), self::PARENT_TYPES, true ) ) {
			wp_send_json_error( [ 'message' => 'invalid_parent' ], 400 );
		}

		$lesson = get_post( $lesson_id );
		if ( ! $lesson instanceof WP_Post || 'vl_lesson' !== $lesson->post_type ) {
			wp_send_json_error( [ 'message' => 'invalid_lesson' ], 400 );
		}

		// Honour the "unattached only" contract — never silently move a
		// lesson away from a course or module it already belongs to.
		if ( 0 !== (int) $lesson->post_parent ) {
			wp_send_json_error( [ 'message' => 'already_attached' ], 409 );
		}

		if ( ! current_user_can( 'edit_post', $lesson_id ) || ! current_user_can( 'edit_post', $parent_id ) ) {
			wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
		}

		wp_update_post(
			[
				'ID'          => $lesson_id,
				'post_parent' => $parent_id,
				'menu_order'  => $this->next_menu_order( $parent_id ),
			]
		);

		$title = '' !== $lesson->post_title ? $lesson->post_title : sprintf( '#%d', $lesson_id );
		wp_send_json_success(
			[
				'id'       => $lesson_id,
				'title'    => $title,
				'editLink' => (string) get_edit_post_link( $lesson_id, 'raw' ),
			]
		);
	}

	public function detach(): void {
		$this->require_nonce( self::DETACH_ACTION );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$lesson_id = isset( $_POST['lesson_id'] ) ? absint( wp_unslash( $_POST['lesson_id'] ) ) : 0;
		if ( $lesson_id <= 0 ) {
			wp_send_json_error( [ 'message' => 'missing_ids' ], 400 );
		}

		if ( 'vl_lesson' !== get_post_type( $lesson_id ) ) {
			wp_send_json_error( [ 'message' => 'invalid_lesson' ], 400 );
		}

		if ( ! current_user_can( 'edit_post', $lesson_id ) ) {
			wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
		}

		wp_update_post(
			[
				'ID'          => $lesson_id,
				'post_parent' => 0,
			]
		);

		wp_send_json_success( [ 'id' => $lesson_id ] );
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
	 * Next `menu_order` for a parent's lesson list — one past the current
	 * maximum so an attached lesson lands at the bottom. The parent is a
	 * `vl_course` (course-direct) or a `vl_module`.
	 */
	private function next_menu_order( int $parent_id ): int {
		$siblings = get_posts(
			[
				'post_type'      => 'vl_lesson',
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
