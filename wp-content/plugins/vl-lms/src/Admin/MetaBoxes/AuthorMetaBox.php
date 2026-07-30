<?php

declare(strict_types=1);

namespace VL\LMS\Admin\MetaBoxes;

use WP_Post;
use WP_User;

/**
 * "Автор курсу" / "Автор вебінару" meta-box.
 *
 * Side-column UI for changing the lead instructor of a `vl_course` or
 * `vl_webinar`, which the domain stores as plain `post_author` (see
 * {@see \VL\LMS\Services\CourseInstructors\AuthorSyncService}, which
 * mirrors it into the `vl_course_instructors` lead row for both entity
 * types). Core's own `authordiv` would technically do this, but it hides
 * behind Screen Options, is not part of the Ukrainian admin vocabulary,
 * and lists every user capable of editing the post type instead of the
 * instructor pool — so `AdminProvider` removes it and this box takes its
 * place.
 *
 * Instantiated once per supported post type (mirroring the per-parent
 * child-list boxes) rather than subclassed per type — only the title and
 * box id vary.
 *
 * The select reuses core's `post_author_override` field name on purpose:
 * `edit_post()` → `_wp_translate_postdata()` consumes it, enforces the
 * post type's `edit_others_*` primitive, and writes `post_author` before
 * any `save_post` listener runs — which is what lets `AuthorSyncService`
 * see the new author and reconcile the instructors table in the same save
 * pass. That is also why {@see self::save()} is a deliberate no-op.
 *
 * Editors without the `edit_others_*` primitive (instructors — both caps
 * are administrator-only by design) get a read-only view of the current
 * author instead of the select.
 *
 * @author Tymofii Synianskyi
 */
class AuthorMetaBox extends AbstractMetaBox {

	public function id(): string {
		return 'vl_webinar' === $this->post_type() ? 'vl_lms_webinar_author' : 'vl_lms_course_author';
	}

	public function title(): string {
		if ( 'vl_webinar' === $this->post_type() ) {
			return __( 'Автор вебінару', 'vl-lms' );
		}
		return __( 'Автор курсу', 'vl-lms' );
	}

	public function context(): string {
		return 'side';
	}

	public function priority(): string {
		return 'high';
	}

	public function render( WP_Post $post ): void {
		$author_id = (int) $post->post_author;

		if ( ! $this->can_change_author( $post ) ) {
			$author = get_userdata( $author_id );
			$this->render_readonly_row(
				__( 'Автор', 'vl-lms' ),
				$author instanceof WP_User ? $author->display_name : '',
				__( 'Змінити автора може адміністратор', 'vl-lms' )
			);
			return;
		}

		echo '<div class="vl-lms-row vl-lms-row--full">';
		printf(
			'<label for="vl-lms-author">%s</label>',
			esc_html__( 'Автор (головний інструктор)', 'vl-lms' )
		);
		wp_dropdown_users(
			[
				'name'             => 'post_author_override',
				'id'               => 'vl-lms-author',
				'selected'         => $author_id,
				// Keep the current author listed even when they are not an
				// instructor (e.g. an administrator created the post), so
				// an untouched form never reassigns the post silently.
				'include_selected' => true,
				'role'             => 'instructor',
				'show'             => 'display_name_with_login',
			]
		);
		echo '</div>';
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Попередній автор залишиться в команді як ко-інструктор.', 'vl-lms' )
		);
	}

	/**
	 * Deliberate no-op — see the class docblock. Persistence belongs to
	 * core's `post_author_override` handling; writing `post_author` here
	 * as well would be a second, later write racing the same field.
	 */
	public function save( int $post_id, WP_Post $post ): void {
		unset( $post_id, $post );
	}

	/**
	 * Same gate core uses for `authordiv`: the post type's
	 * `edit_others_posts` primitive, which maps to `edit_others_vl_courses`
	 * / `edit_others_vl_webinars`.
	 */
	private function can_change_author( WP_Post $post ): bool {
		$post_type_object = get_post_type_object( $post->post_type );
		if ( null === $post_type_object ) {
			return false;
		}
		return current_user_can( $post_type_object->cap->edit_others_posts );
	}
}
