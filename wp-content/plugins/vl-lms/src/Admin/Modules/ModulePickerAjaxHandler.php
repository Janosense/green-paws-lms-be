<?php

declare(strict_types=1);

namespace VL\LMS\Admin\Modules;

use WP_Post;

/**
 * AJAX backend for the "pick an existing module" feature on the course
 * edit screen ({@see \VL\LMS\Admin\MetaBoxes\ChildList\ModuleListMetaBox}).
 *
 * Three verbs, each on its own nonce action:
 *  - `search`  → list unattached (`post_parent = 0`) `vl_module` posts
 *                matching a title query, so attaching one never steals it
 *                from another course.
 *  - `attach`  → re-parent an unattached module to a course and place it
 *                at the end of the list (`menu_order = max + 1`).
 *  - `detach`  → unlink a module (`post_parent = 0`) without deleting it.
 *
 * Mirrors {@see \VL\LMS\Admin\Reorder\ReorderAjaxHandler}: explicit nonce
 * check, per-post `current_user_can( 'edit_post', … )`, `wp_send_json_*`.
 *
 * Not declared `final` — the unit tests subclass for behaviour
 * verification with Mockery.
 *
 * @author Tymofii Synianskyi
 */
class ModulePickerAjaxHandler {

	public const string SEARCH_ACTION = 'vl_lms_module_search';
	public const string ATTACH_ACTION = 'vl_lms_module_attach';
	public const string DETACH_ACTION = 'vl_lms_module_detach';

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

		$modules = get_posts(
			[
				'post_type'      => 'vl_module',
				'post_parent'    => 0,
				'post_status'    => 'any',
				's'              => $query,
				'posts_per_page' => self::MAX_RESULTS,
				'orderby'        => 'title',
				'order'          => 'ASC',
			]
		);

		$payload = [];
		if ( is_array( $modules ) ) {
			foreach ( $modules as $module ) {
				if ( ! $module instanceof WP_Post ) {
					continue;
				}
				$title     = '' !== $module->post_title ? $module->post_title : sprintf( '#%d', (int) $module->ID );
				$payload[] = [
					'id'    => (int) $module->ID,
					'title' => $title,
				];
			}
		}

		wp_send_json_success( $payload );
	}

	public function attach(): void {
		$this->require_nonce( self::ATTACH_ACTION );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$course_id = isset( $_POST['course_id'] ) ? absint( wp_unslash( $_POST['course_id'] ) ) : 0;
		$module_id = isset( $_POST['module_id'] ) ? absint( wp_unslash( $_POST['module_id'] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( $course_id <= 0 || $module_id <= 0 ) {
			wp_send_json_error( [ 'message' => 'missing_ids' ], 400 );
		}

		if ( 'vl_course' !== get_post_type( $course_id ) ) {
			wp_send_json_error( [ 'message' => 'invalid_course' ], 400 );
		}

		$module = get_post( $module_id );
		if ( ! $module instanceof WP_Post || 'vl_module' !== $module->post_type ) {
			wp_send_json_error( [ 'message' => 'invalid_module' ], 400 );
		}

		// Honour the "unattached only" contract — never silently move a
		// module away from a course it already belongs to.
		if ( 0 !== (int) $module->post_parent ) {
			wp_send_json_error( [ 'message' => 'already_attached' ], 409 );
		}

		if ( ! current_user_can( 'edit_post', $module_id ) || ! current_user_can( 'edit_post', $course_id ) ) {
			wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
		}

		wp_update_post(
			[
				'ID'          => $module_id,
				'post_parent' => $course_id,
				'menu_order'  => $this->next_menu_order( $course_id ),
			]
		);

		$title = '' !== $module->post_title ? $module->post_title : sprintf( '#%d', $module_id );
		wp_send_json_success(
			[
				'id'       => $module_id,
				'title'    => $title,
				'editLink' => (string) get_edit_post_link( $module_id, 'raw' ),
			]
		);
	}

	public function detach(): void {
		$this->require_nonce( self::DETACH_ACTION );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$module_id = isset( $_POST['module_id'] ) ? absint( wp_unslash( $_POST['module_id'] ) ) : 0;
		if ( $module_id <= 0 ) {
			wp_send_json_error( [ 'message' => 'missing_ids' ], 400 );
		}

		if ( 'vl_module' !== get_post_type( $module_id ) ) {
			wp_send_json_error( [ 'message' => 'invalid_module' ], 400 );
		}

		if ( ! current_user_can( 'edit_post', $module_id ) ) {
			wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
		}

		wp_update_post(
			[
				'ID'          => $module_id,
				'post_parent' => 0,
			]
		);

		wp_send_json_success( [ 'id' => $module_id ] );
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
	 * Next `menu_order` for a course's module list — one past the current
	 * maximum so an attached module lands at the bottom.
	 */
	private function next_menu_order( int $course_id ): int {
		$siblings = get_posts(
			[
				'post_type'      => 'vl_module',
				'post_parent'    => $course_id,
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
