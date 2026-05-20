<?php

declare(strict_types=1);

namespace VL\LMS\Admin\Groups;

use VL\LMS\Repositories\GroupAccessRepository;
use VL\LMS\Repositories\GroupMemberRepository;
use VL\LMS\Repositories\GroupRepository;

/**
 * Admin "Групи" list page-controller (`?page=vl-lms-groups`).
 *
 * Dispatches between list view (no `action` query arg) and the
 * {@see GroupDetailPage} edit view (`?action=edit&id=N`). Mirrors the
 * existing `Admin/Orders/OrdersListPage` + per-row edit-link pattern.
 *
 * Not declared `final` — unit tests subclass to bypass `wp_die` so the
 * capability guard can be asserted.
 *
 * @author Tymofii Synianskyi
 */
class GroupsListPage {

	public const string PAGE_SLUG  = 'vl-lms-groups';
	public const string CAPABILITY = 'vl_manage_groups';

	public function __construct(
		private readonly GroupRepository $groups,
		private readonly GroupMemberRepository $members,
		private readonly GroupAccessRepository $access,
		private readonly GroupDetailPage $detail_page
	) {
	}

	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			$this->forbidden();
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing param.
		$action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['action'] ) ) : '';

		if ( 'edit' === $action ) {
			$this->detail_page->render();
			return;
		}

		$this->render_list();
	}

	private function render_list(): void {
		$table = new GroupsListTable( $this->groups, $this->members, $this->access );
		$table->prepare_items();

		echo '<div class="wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Групи', 'vl-lms' ) . '</h1>';
		echo '<hr class="wp-header-end" />';

		$this->render_admin_notices();

		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE_SLUG ) . '" />';
		$table->search_box( __( 'Пошук', 'vl-lms' ), 'group-search' );
		$table->display();
		echo '</form>';

		$this->render_create_form();

		echo '</div>';
	}

	private function render_create_form(): void {
		$action_url = admin_url( 'admin-post.php' );

		echo '<h2 style="margin-top:32px">' . esc_html__( 'Створити нову групу', 'vl-lms' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( $action_url ) . '" class="vl-admin-group-create">';
		echo '<input type="hidden" name="action" value="' . esc_attr( GroupFormHandler::ACTION_CREATE ) . '" />';
		wp_nonce_field( GroupFormHandler::ACTION_CREATE );

		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th><label for="vl-group-name">' . esc_html__( 'Назва', 'vl-lms' ) . '</label></th>';
		echo '<td><input type="text" id="vl-group-name" name="name" class="regular-text" required /></td></tr>';

		echo '<tr><th><label for="vl-group-description">' . esc_html__( 'Опис', 'vl-lms' ) . '</label></th>';
		echo '<td><textarea id="vl-group-description" name="description" rows="3" class="large-text"></textarea></td></tr>';

		echo '<tr><th><label for="vl-group-max-members">' . esc_html__( 'Максимум учасників', 'vl-lms' ) . '</label></th>';
		echo '<td><input type="number" id="vl-group-max-members" name="max_members" min="1" class="small-text" />';
		echo ' <span class="description">' . esc_html__( 'Залиште порожнім для необмеженої кількості.', 'vl-lms' ) . '</span></td></tr>';

		echo '</tbody></table>';

		submit_button( __( 'Створити групу', 'vl-lms' ), 'primary' );
		echo '</form>';
	}

	private function render_admin_notices(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice key.
		$notice = isset( $_GET['notice'] ) ? sanitize_key( wp_unslash( (string) $_GET['notice'] ) ) : '';
		if ( '' === $notice ) {
			return;
		}

		[ $level, $message ] = match ( $notice ) {
			'group_created'  => [ 'success', __( 'Групу створено.', 'vl-lms' ) ],
			'group_archived' => [ 'success', __( 'Групу архівовано.', 'vl-lms' ) ],
			'invalid_name'   => [ 'error', __( 'Назва не може бути порожньою.', 'vl-lms' ) ],
			'duplicate_slug' => [ 'error', __( 'Група з таким slug вже існує. Спробуйте іншу назву.', 'vl-lms' ) ],
			default          => [ '', '' ],
		};

		if ( '' === $level ) {
			return;
		}

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $level ),
			esc_html( $message )
		);
	}

	/**
	 * Indirected so unit tests can subclass and bypass `wp_die`.
	 */
	protected function forbidden(): void {
		wp_die( esc_html__( 'Доступ заборонено.', 'vl-lms' ), '', [ 'response' => 403 ] );
	}
}
