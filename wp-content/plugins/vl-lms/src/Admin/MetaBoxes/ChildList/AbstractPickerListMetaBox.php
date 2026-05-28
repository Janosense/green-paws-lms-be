<?php

declare(strict_types=1);

namespace VL\LMS\Admin\MetaBoxes\ChildList;

use WP_Post;

/**
 * Sortable child-list meta-box with an add / unlink picker, rendered on a
 * parent CPT edit screen.
 *
 * Generalises the affordances shared by the course's **Модулі**, the
 * course / module **Уроки** and the lesson **Теми** boxes:
 *
 *  - an "Add new" button → `post-new.php?post_type=<child>&vl_parent_id=…`
 *    (the new post's parent is pre-selected by its typed meta-box),
 *  - a search picker that attaches an existing, **unattached**
 *    (`post_parent = 0`) child via AJAX,
 *  - per-row "Редагувати" + "Відкріпити" (detach back to `post_parent = 0`).
 *
 * The markup is entity-agnostic: the picker `<div>` and the unlink button
 * carry `data-entity="<entity>"`, and the admin JS scopes itself to the
 * enclosing `.postbox`, so one handler drives every screen. Each concrete
 * box supplies its entity key (which selects the JS config + AJAX verbs in
 * `VL_LMS_ADMIN.pickers`) and its Ukrainian button labels.
 *
 * Like {@see ModuleListMetaBox}, the `<ul class="vl-sortable-list">` is
 * rendered even when empty so the picker's AJAX success handler has a
 * stable container to append rows into; the "no items" hint is a sibling
 * the JS toggles.
 *
 * @author Tymofii Synianskyi
 */
abstract class AbstractPickerListMetaBox extends AbstractChildListMetaBox {

	public function __construct(
		string $parent_type,
		string $child_type,
		private readonly string $entity
	) {
		parent::__construct( $parent_type, $child_type );
	}

	/** Label for the "create new child" button, e.g. "Додати урок". */
	abstract protected function add_new_label(): string;

	/** Label above the search field, e.g. "Або обрати існуючий урок". */
	abstract protected function search_label(): string;

	/** Placeholder for the search input, e.g. "Назва уроку". */
	abstract protected function search_placeholder(): string;

	public function render( WP_Post $post ): void {
		$parent_id   = (int) $post->ID;
		$children    = $this->query_children( $parent_id );
		$add_new_url = add_query_arg(
			[
				'post_type'    => $this->child_type,
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
			esc_html( $this->add_new_label() )
		);

		printf(
			'<div class="vl-lms-entity-search" data-entity="%1$s" data-parent-id="%2$d">'
			. '<label for="vl-lms-%1$s-search-input">%3$s</label>'
			. '<input type="text" id="vl-lms-%1$s-search-input" placeholder="%4$s" autocomplete="off" />'
			. '<ul class="vl-lms-entity-search-results" hidden></ul>'
			. '</div>',
			esc_attr( $this->entity ),
			(int) $parent_id,
			esc_html( $this->search_label() ),
			esc_attr( $this->search_placeholder() )
		);
	}

	/**
	 * Render a single child row: drag handle, title, edit link, and an
	 * unlink button. The `data-id` attribute feeds both the reorder
	 * contract and the JS detach handler; `data-entity` routes the detach
	 * to the right AJAX verbs.
	 */
	private function render_row( WP_Post $child ): void {
		$title     = '' !== $child->post_title ? $child->post_title : sprintf( '#%d', (int) $child->ID );
		$edit_link = (string) get_edit_post_link( (int) $child->ID );
		printf(
			'<li data-id="%1$d">'
			. '<span class="vl-sortable-handle">⋮⋮</span> '
			. '<span class="vl-sortable-title">%2$s</span> '
			. '<a class="vl-sortable-edit" href="%3$s">%4$s</a> '
			. '<button type="button" class="button-link vl-lms-entity-unlink" data-entity="%5$s" data-id="%1$d">%6$s</button>'
			. '</li>',
			(int) $child->ID,
			esc_html( $title ),
			esc_url( $edit_link ),
			esc_html__( 'Редагувати', 'vl-lms' ),
			esc_attr( $this->entity ),
			esc_html__( 'Відкріпити', 'vl-lms' )
		);
	}
}
