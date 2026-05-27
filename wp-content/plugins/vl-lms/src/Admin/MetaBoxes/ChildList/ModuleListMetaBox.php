<?php

declare(strict_types=1);

namespace VL\LMS\Admin\MetaBoxes\ChildList;

use WP_Post;

/**
 * Sortable module list rendered on the `vl_course` edit screen.
 *
 * Overrides {@see AbstractChildListMetaBox::render()} to mirror
 * {@see TopicListMetaBox}: every row gets an edit link plus an "unlink"
 * button, and below the list sit two ways to add a module — a "create
 * new" button (`post-new.php?post_type=vl_module&vl_parent_id=…`) and a
 * search picker that attaches an existing, unattached module via AJAX
 * ({@see \VL\LMS\Admin\Modules\ModulePickerAjaxHandler}).
 *
 * Unlike Topics, the `<ul class="vl-sortable-list">` is rendered even
 * when empty so the picker's AJAX success handler has a stable container
 * to append rows into; the "no items" hint is a sibling that the admin
 * JS toggles.
 *
 * @author Tymofii Synianskyi
 */
class ModuleListMetaBox extends AbstractChildListMetaBox {

	public function __construct() {
		parent::__construct( 'vl_course', 'vl_module' );
	}

	public function id(): string {
		return 'vl_lms_module_list';
	}

	public function title(): string {
		return 'Модулі';
	}

	public function render( WP_Post $post ): void {
		$course_id   = (int) $post->ID;
		$children    = $this->query_children( $course_id );
		$add_new_url = add_query_arg(
			[
				'post_type'    => 'vl_module',
				'vl_parent_id' => $course_id,
			],
			admin_url( 'post-new.php' )
		);

		printf(
			'<ul class="vl-sortable-list" data-parent-id="%1$d" data-nonce="%2$s"%3$s>',
			(int) $course_id,
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
			esc_html__( 'Додати модуль', 'vl-lms' )
		);

		printf(
			'<div class="vl-lms-module-search" data-course-id="%1$d">'
			. '<label for="vl-lms-module-search-input">%2$s</label>'
			. '<input type="text" id="vl-lms-module-search-input" placeholder="%3$s" autocomplete="off" />'
			. '<ul class="vl-lms-module-search-results" hidden></ul>'
			. '</div>',
			(int) $course_id,
			esc_html__( 'Або обрати існуючий модуль', 'vl-lms' ),
			esc_attr__( 'Назва модуля', 'vl-lms' )
		);
	}

	/**
	 * Render a single module row: drag handle, title, edit link, and an
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
			. '<button type="button" class="button-link vl-lms-module-unlink" data-id="%1$d">%5$s</button>'
			. '</li>',
			(int) $child->ID,
			esc_html( $title ),
			esc_url( $edit_link ),
			esc_html__( 'Редагувати', 'vl-lms' ),
			esc_html__( 'Відкріпити', 'vl-lms' )
		);
	}
}
