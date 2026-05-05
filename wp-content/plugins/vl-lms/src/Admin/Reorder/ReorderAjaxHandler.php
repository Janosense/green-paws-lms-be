<?php

declare(strict_types=1);

namespace VL\LMS\Admin\Reorder;

/**
 * `wp_ajax_vl_lms_reorder` handler — drag-drop reorder for child CPTs.
 *
 * Accepts an ordered array of post IDs and writes a zero-based
 * `menu_order` to each row that the current user can edit. Posts the
 * user cannot edit (or that no longer exist) are silently skipped so a
 * partially-stale list doesn't fail the whole batch.
 *
 * Hard cap of 200 items per request — the parent meta-boxes load at
 * most that many children, and the cap shields the handler from
 * accidental DoS via a malformed POST body.
 *
 * Not declared `final` — the unit tests subclass for behaviour
 * verification with Mockery.
 *
 * @author Tymofii Synianskyi
 */
class ReorderAjaxHandler {

	private const string NONCE_ACTION = 'vl_lms_reorder';
	private const int MAX_ITEMS       = 200;

	public function handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce checked explicitly below.
		$nonce_raw = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce_raw, self::NONCE_ACTION ) ) {
			// wp_send_json_error → wp_die → exit; further code does not run.
			wp_send_json_error( [ 'message' => 'Invalid nonce' ], 403 );
		}

		$ids_raw = isset( $_POST['ids'] ) ? wp_unslash( $_POST['ids'] ) : null;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( ! is_array( $ids_raw ) || [] === $ids_raw ) {
			wp_send_json_error( [ 'message' => 'No IDs provided' ], 400 );
		}

		if ( count( $ids_raw ) > self::MAX_ITEMS ) {
			wp_send_json_error( [ 'message' => 'Too many items' ], 400 );
		}

		$ids = array_map( 'absint', $ids_raw );

		$updated = 0;
		foreach ( $ids as $menu_order => $id ) {
			if ( $id <= 0 ) {
				continue;
			}
			$post = get_post( $id );
			if ( null === $post ) {
				continue;
			}
			if ( ! current_user_can( 'edit_post', $id ) ) {
				continue;
			}
			wp_update_post(
				[
					'ID'         => $id,
					'menu_order' => $menu_order,
				]
			);
			++$updated;
		}

		wp_send_json_success( [ 'updated' => $updated ] );
	}
}
