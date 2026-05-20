<?php

declare(strict_types=1);

namespace VL\LMS\Admin\Groups;

use VL\LMS\Domain\Group\AccessEntityType;
use VL\LMS\Domain\Group\Group;
use VL\LMS\Domain\Group\GroupAccess;
use VL\LMS\Domain\Group\GroupMember;
use VL\LMS\Domain\Group\GroupStatus;
use VL\LMS\Repositories\GroupAccessRepository;
use VL\LMS\Repositories\GroupMemberRepository;
use VL\LMS\Repositories\GroupRepository;
use WP_Post;
use WP_User;

/**
 * Admin "Редагувати групу" screen (`?page=vl-lms-groups&action=edit&id=N`).
 *
 * Three sections rendered on a single page, each posting back to its
 * own `admin-post.php` action handled by {@see GroupFormHandler}:
 *   - group fields (name / description / status)
 *   - active members + add/remove
 *   - active course-access rows + grant/revoke
 *
 * @author Tymofii Synianskyi
 */
class GroupDetailPage {

	public function __construct(
		private readonly GroupRepository $groups,
		private readonly GroupMemberRepository $members,
		private readonly GroupAccessRepository $access
	) {
	}

	public function render(): void {
		$id = $this->request_id();
		if ( null === $id ) {
			$this->render_not_found();
			return;
		}

		$group = $this->groups->find_by_id( $id );
		if ( null === $group ) {
			$this->render_not_found();
			return;
		}

		echo '<div class="wrap">';
		printf(
			'<h1 class="wp-heading-inline">%s</h1>',
			esc_html(
				sprintf(
					/* translators: %s: group name */
					__( 'Група: %s', 'vl-lms' ),
					$group->name
				)
			)
		);
		printf(
			' <a href="%s" class="page-title-action">%s</a>',
			esc_url( admin_url( 'admin.php?page=' . GroupsListPage::PAGE_SLUG ) ),
			esc_html__( '← До списку', 'vl-lms' )
		);
		echo '<hr class="wp-header-end" />';

		$this->render_notice();

		echo '<div class="vl-admin-group-detail">';
		$this->render_fields_section( $group );
		$this->render_members_section( $group );
		$this->render_access_section( $group );
		echo '</div>';

		echo '</div>';
	}

	private function render_fields_section( Group $group ): void {
		echo '<section class="vl-admin-card">';
		echo '<h2>' . esc_html__( 'Параметри групи', 'vl-lms' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( GroupFormHandler::ACTION_UPDATE ) . '" />';
		echo '<input type="hidden" name="group_id" value="' . esc_attr( (string) $group->id ) . '" />';
		wp_nonce_field( GroupFormHandler::ACTION_UPDATE . '_' . $group->id );

		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th><label for="vl-group-name">' . esc_html__( 'Назва', 'vl-lms' ) . '</label></th>';
		echo '<td><input type="text" id="vl-group-name" name="name" class="regular-text" value="' . esc_attr( $group->name ) . '" required /></td></tr>';

		echo '<tr><th><label>' . esc_html__( 'Slug', 'vl-lms' ) . '</label></th>';
		echo '<td><code>' . esc_html( $group->slug ) . '</code>';
		echo ' <span class="description">' . esc_html__( 'Slug фіксується при створенні і не редагується.', 'vl-lms' ) . '</span></td></tr>';

		echo '<tr><th><label for="vl-group-description">' . esc_html__( 'Опис', 'vl-lms' ) . '</label></th>';
		echo '<td><textarea id="vl-group-description" name="description" rows="3" class="large-text">' . esc_textarea( (string) $group->description ) . '</textarea></td></tr>';

		echo '<tr><th><label for="vl-group-status">' . esc_html__( 'Статус', 'vl-lms' ) . '</label></th>';
		echo '<td><select id="vl-group-status" name="status">';
		foreach ( [ [ GroupStatus::ACTIVE, 'Активна' ], [ GroupStatus::ARCHIVED, 'Архів' ] ] as [ $case, $label ] ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $case->value ),
				selected( $group->status, $case, false ),
				esc_html( $label )
			);
		}
		echo '</select></td></tr>';

		echo '</tbody></table>';
		submit_button( __( 'Зберегти зміни', 'vl-lms' ), 'primary' );
		echo '</form>';
		echo '</section>';
	}

	private function render_members_section( Group $group ): void {
		echo '<section class="vl-admin-card">';
		echo '<h2>' . esc_html__( 'Учасники', 'vl-lms' ) . '</h2>';

		$members = $this->members->list_active_members( $group->id );
		if ( null !== $group->max_members ) {
			printf(
				'<p class="description">%s</p>',
				esc_html(
					sprintf(
					/* translators: 1: current count, 2: max */
						__( 'Зараз: %1$d з %2$d.', 'vl-lms' ),
						count( $members ),
						$group->max_members
					)
				)
			);
		}

		$this->render_members_table( $group, $members );
		$this->render_member_add_form( $group );

		echo '</section>';
	}

	/**
	 * @param list<GroupMember> $members
	 */
	private function render_members_table( Group $group, array $members ): void {
		if ( [] === $members ) {
			echo '<p>' . esc_html__( 'Учасників ще немає.', 'vl-lms' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Користувач', 'vl-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Email', 'vl-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Долучився', 'vl-lms' ) . '</th>';
		echo '<th></th>';
		echo '</tr></thead><tbody>';

		foreach ( $members as $member ) {
			$user = get_userdata( $member->user_id );
			$name = $user instanceof WP_User ? $user->display_name : sprintf( '#%d (видалено)', $member->user_id );
			$mail = $user instanceof WP_User ? (string) $user->user_email : '—';

			$remove_url = wp_nonce_url(
				add_query_arg(
					[
						'action'   => GroupFormHandler::ACTION_MEMBER_REMOVE,
						'group_id' => $group->id,
						'user_id'  => $member->user_id,
					],
					admin_url( 'admin-post.php' )
				),
				GroupFormHandler::ACTION_MEMBER_REMOVE . '_' . $group->id . '_' . $member->user_id
			);

			$confirm = wp_json_encode( __( 'Видалити учасника з групи?', 'vl-lms' ) );
			$confirm = is_string( $confirm ) ? $confirm : '"?"';

			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td><a href="%s" class="button-link delete" onclick="return confirm(%s);">%s</a></td></tr>',
				esc_html( $name ),
				esc_html( $mail ),
				esc_html( $member->joined_at ),
				esc_url( $remove_url ),
				esc_attr( $confirm ),
				esc_html__( 'Видалити', 'vl-lms' )
			);
		}

		echo '</tbody></table>';
	}

	private function render_member_add_form( Group $group ): void {
		echo '<h3>' . esc_html__( 'Додати студента', 'vl-lms' ) . '</h3>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="vl-admin-group-add-member">';
		echo '<input type="hidden" name="action" value="' . esc_attr( GroupFormHandler::ACTION_MEMBER_ADD ) . '" />';
		echo '<input type="hidden" name="group_id" value="' . esc_attr( (string) $group->id ) . '" />';
		wp_nonce_field( GroupFormHandler::ACTION_MEMBER_ADD . '_' . $group->id );

		echo '<p>';
		echo '<label for="vl-group-add-user-search" class="screen-reader-text">' . esc_html__( 'Пошук студента', 'vl-lms' ) . '</label>';
		echo '<input type="text" id="vl-group-add-user-search" class="regular-text vl-admin-user-search" placeholder="' . esc_attr__( 'Шукати студента за email або іменем…', 'vl-lms' ) . '" autocomplete="off" />';
		echo '<input type="hidden" id="vl-group-add-user-id" name="user_id" value="" required />';
		echo '<span class="vl-admin-user-search-results"></span>';
		echo '</p>';

		submit_button( __( 'Додати до групи', 'vl-lms' ), 'secondary', '', false );
		echo '</form>';
	}

	private function render_access_section( Group $group ): void {
		echo '<section class="vl-admin-card">';
		echo '<h2>' . esc_html__( 'Безкоштовний доступ до курсів', 'vl-lms' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Кожен активний учасник групи автоматично отримує доступ до курсів зі списку.', 'vl-lms' ) . '</p>';

		$access = $this->access->list_active_for_group( $group->id );
		$this->render_access_table( $group, $access );
		$this->render_access_add_form( $group );

		echo '</section>';
	}

	/**
	 * @param list<GroupAccess> $rows
	 */
	private function render_access_table( Group $group, array $rows ): void {
		if ( [] === $rows ) {
			echo '<p>' . esc_html__( 'Доступу ще не надано.', 'vl-lms' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Курс', 'vl-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Надано', 'vl-lms' ) . '</th>';
		echo '<th></th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			if ( AccessEntityType::COURSE !== $row->entity_type ) {
				continue;
			}
			$post  = get_post( $row->entity_id );
			$title = $post instanceof WP_Post ? $post->post_title : sprintf( '#%d (видалено)', $row->entity_id );

			$revoke_url = wp_nonce_url(
				add_query_arg(
					[
						'action'    => GroupFormHandler::ACTION_COURSE_REVOKE,
						'group_id'  => $group->id,
						'course_id' => $row->entity_id,
					],
					admin_url( 'admin-post.php' )
				),
				GroupFormHandler::ACTION_COURSE_REVOKE . '_' . $group->id . '_' . $row->entity_id
			);

			$confirm = wp_json_encode( __( 'Відкликати доступ до курсу для всіх учасників?', 'vl-lms' ) );
			$confirm = is_string( $confirm ) ? $confirm : '"?"';

			printf(
				'<tr><td>%s</td><td>%s</td><td><a href="%s" class="button-link delete" onclick="return confirm(%s);">%s</a></td></tr>',
				esc_html( $title ),
				esc_html( $row->granted_at ),
				esc_url( $revoke_url ),
				esc_attr( $confirm ),
				esc_html__( 'Відкликати', 'vl-lms' )
			);
		}

		echo '</tbody></table>';
	}

	private function render_access_add_form( Group $group ): void {
		echo '<h3>' . esc_html__( 'Надати доступ до курсу', 'vl-lms' ) . '</h3>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="vl-admin-group-add-course">';
		echo '<input type="hidden" name="action" value="' . esc_attr( GroupFormHandler::ACTION_COURSE_GRANT ) . '" />';
		echo '<input type="hidden" name="group_id" value="' . esc_attr( (string) $group->id ) . '" />';
		wp_nonce_field( GroupFormHandler::ACTION_COURSE_GRANT . '_' . $group->id );

		echo '<p>';
		echo '<label for="vl-group-add-course-search" class="screen-reader-text">' . esc_html__( 'Пошук курсу', 'vl-lms' ) . '</label>';
		echo '<input type="text" id="vl-group-add-course-search" class="regular-text vl-admin-course-search" placeholder="' . esc_attr__( 'Шукати опублікований курс…', 'vl-lms' ) . '" autocomplete="off" />';
		echo '<input type="hidden" id="vl-group-add-course-id" name="course_id" value="" required />';
		echo '<span class="vl-admin-course-search-results"></span>';
		echo '</p>';

		submit_button( __( 'Надати доступ', 'vl-lms' ), 'secondary', '', false );
		echo '</form>';
	}

	private function render_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice key.
		$notice = isset( $_GET['notice'] ) ? sanitize_key( wp_unslash( (string) $_GET['notice'] ) ) : '';
		if ( '' === $notice ) {
			return;
		}

		[ $level, $message ] = match ( $notice ) {
			'group_updated'    => [ 'success', __( 'Зміни збережено.', 'vl-lms' ) ],
			'member_added'     => [ 'success', __( 'Студента додано до групи.', 'vl-lms' ) ],
			'member_removed'   => [ 'success', __( 'Студента видалено з групи.', 'vl-lms' ) ],
			'course_granted'   => [ 'success', __( 'Доступ до курсу надано.', 'vl-lms' ) ],
			'course_revoked'   => [ 'success', __( 'Доступ до курсу відкликано.', 'vl-lms' ) ],
			'capacity_full'    => [ 'error', __( 'Група досягла максимальної кількості учасників.', 'vl-lms' ) ],
			'user_not_found'   => [ 'error', __( 'Користувача не знайдено або він не є студентом.', 'vl-lms' ) ],
			'course_not_found' => [ 'error', __( 'Курс не знайдено або не опубліковано.', 'vl-lms' ) ],
			'invalid_name'     => [ 'error', __( 'Назва не може бути порожньою.', 'vl-lms' ) ],
			default            => [ '', '' ],
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

	private function render_not_found(): void {
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Групу не знайдено', 'vl-lms' ) . '</h1>';
		echo '<div class="notice notice-error"><p>' . esc_html__( 'Перевірте, чи правильний посилання, або поверніться до списку.', 'vl-lms' ) . '</p></div>';
		printf(
			'<a href="%s" class="button">%s</a>',
			esc_url( admin_url( 'admin.php?page=' . GroupsListPage::PAGE_SLUG ) ),
			esc_html__( '← До списку', 'vl-lms' )
		);
		echo '</div>';
	}

	private function request_id(): ?int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing param.
		if ( ! isset( $_GET['id'] ) ) {
			return null;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Sanitised below.
		$raw = wp_unslash( (string) $_GET['id'] );
		if ( ! is_numeric( $raw ) ) {
			return null;
		}
		$id = (int) $raw;
		return $id > 0 ? $id : null;
	}
}
