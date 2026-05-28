<?php

declare(strict_types=1);

namespace VL\LMS\Admin\MetaBoxes\ChildList;

use WP_Post;

/**
 * Sortable `vl_lesson` list with an add / unlink picker, rendered on a
 * parent CPT edit screen.
 *
 * A lesson can hang directly off a `vl_course` (course-direct, see
 * {@see CourseLessonListMetaBox}) or off a `vl_module`
 * ({@see LessonListMetaBox}). Both screens render the same affordances —
 * the only difference is the bound parent post type — so the shared
 * markup lives here:
 *
 *  - a "Додати урок" button → `post-new.php?post_type=vl_lesson&vl_parent_id=…`
 *    (the new lesson's parent is pre-selected by {@see \VL\LMS\Admin\MetaBoxes\LessonMetaBox}),
 *  - a search picker that attaches an existing, **unattached**
 *    (`post_parent = 0`) lesson via AJAX
 *    ({@see \VL\LMS\Admin\Lessons\LessonPickerAjaxHandler}),
 *  - per-row "Редагувати" + "Відкріпити" (detach back to `post_parent = 0`).
 *
 * Mirrors {@see ModuleListMetaBox}: the `<ul class="vl-sortable-list">`
 * is rendered even when empty so the picker's AJAX success handler has a
 * stable container to append rows into; the "no items" hint is a sibling
 * the admin JS toggles. The JS scopes itself to the enclosing `.postbox`,
 * so a single handler drives both the course and module screens.
 *
 * @author Tymofii Synianskyi
 */
abstract class AbstractLessonListMetaBox extends AbstractChildListMetaBox {

	public function __construct( string $parent_type ) {
		parent::__construct( $parent_type, 'vl_lesson' );
	}

	public function render( WP_Post $post ): void {
		$parent_id   = (int) $post->ID;
		$children    = $this->query_children( $parent_id );
		$add_new_url = add_query_arg(
			[
				'post_type'    => 'vl_lesson',
				'vl_parent_id' => $parent_id,
			],
			admin_url( 'post-new.php' )
		);

		printf(
			'<ul class="vl-sortable-list" data-parent-id="%1$d" data-nonce="%2$s"%3$s>',
			(int) $parent_id,
			esc_attr( wp_create_nonce( 'vl_lms_reorder' ) ),
			[] === $children ? ' hidden' : ''
		);
		foreach ( $children as $child ) {
			$this->render_row( $child );
		}
		echo '</ul>';

		printf(
			'<p class="vl-lms-empty"%2$s>%1$s</p>',
			esc_html__( 'Немає елементів', 'vl-lms' ),
			[] === $children ? '' : ' hidden'
		);

		printf(
			'<p class="vl-lms-add-new"><a class="button" href="%1$s">%2$s</a></p>',
			esc_url( $add_new_url ),
			esc_html__( 'Додати урок', 'vl-lms' )
		);

		printf(
			'<div class="vl-lms-lesson-search" data-parent-id="%1$d">'
			. '<label for="vl-lms-lesson-search-input">%2$s</label>'
			. '<input type="text" id="vl-lms-lesson-search-input" placeholder="%3$s" autocomplete="off" />'
			. '<ul class="vl-lms-lesson-search-results" hidden></ul>'
			. '</div>',
			(int) $parent_id,
			esc_html__( 'Або обрати існуючий урок', 'vl-lms' ),
			esc_attr__( 'Назва уроку', 'vl-lms' )
		);
	}

	/**
	 * Render a single lesson row: drag handle, title, edit link, and an
	 * unlink button. The `data-id` attribute feeds both the reorder
	 * contract and the JS detach handler.
	 */
	private function render_row( WP_Post $child ): void {
		$title     = '' !== $child->post_title ? $child->post_title : sprintf( '#%d', (int) $child->ID );
		$edit_link = (string) get_edit_post_link( (int) $child->ID );
		printf(
			'<li data-id="%1$d">'
			. '<span class="vl-sortable-handle">⋮⋮</span> '
			. '<span class="vl-sortable-title">%2$s</span> '
			. '<a class="vl-sortable-edit" href="%3$s">%4$s</a> '
			. '<button type="button" class="button-link vl-lms-lesson-unlink" data-id="%1$d">%5$s</button>'
			. '</li>',
			(int) $child->ID,
			esc_html( $title ),
			esc_url( $edit_link ),
			esc_html__( 'Редагувати', 'vl-lms' ),
			esc_html__( 'Відкріпити', 'vl-lms' )
		);
	}
}
