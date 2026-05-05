<?php

declare(strict_types=1);

namespace VL\LMS\Admin\MetaBoxes\ChildList;

use WP_Post;

/**
 * Read-only sortable list of child posts on a parent CPT edit screen.
 *
 * Concrete subclasses bind a `(parent_post_type, child_post_type)`
 * pair. The meta-box renders an unordered list keyed by `data-id`
 * attributes that the admin JS turns into a jQuery UI Sortable; drop
 * events POST the new ordering to {@see \VL\LMS\Admin\Reorder\ReorderAjaxHandler}
 * which writes back `menu_order`.
 *
 * No save handler — `menu_order` is updated through the AJAX endpoint,
 * never through `save_post`. Keeps the box completely passive on form
 * submit so it doesn't fight the typed Phase 9.0 meta-boxes.
 *
 * @author Tymofii Synianskyi
 */
abstract class AbstractChildListMetaBox {

	public function __construct(
		protected readonly string $post_type,
		protected readonly string $child_type
	) {
	}

	final public function post_type(): string {
		return $this->post_type;
	}

	abstract public function id(): string;

	abstract public function title(): string;

	public function register(): void {
		add_meta_box(
			$this->id(),
			$this->title(),
			[ $this, 'render' ],
			$this->post_type,
			'normal',
			'low'
		);
	}

	public function render( WP_Post $post ): void {
		$children = $this->query_children( (int) $post->ID );

		if ( [] === $children ) {
			echo '<p class="vl-lms-empty">Немає елементів</p>';
			return;
		}

		printf(
			'<ul class="vl-sortable-list" data-parent-id="%1$d" data-nonce="%2$s">',
			(int) $post->ID,
			esc_attr( wp_create_nonce( 'vl_lms_reorder' ) )
		);

		foreach ( $children as $child ) {
			$title = '' !== $child->post_title ? $child->post_title : sprintf( '#%d', (int) $child->ID );
			printf(
				'<li data-id="%1$d"><span class="vl-sortable-handle">⋮⋮</span> <span class="vl-sortable-title">%2$s</span></li>',
				(int) $child->ID,
				esc_html( $title )
			);
		}

		echo '</ul>';
	}

	/**
	 * Look up child posts of `$parent_id` that belong to `$this->child_type`,
	 * ordered by `menu_order` ASC then `date` ASC. Returned as an indexed list.
	 *
	 * @return list<WP_Post>
	 */
	protected function query_children( int $parent_id ): array {
		$posts = get_posts(
			[
				'post_type'      => $this->child_type,
				'post_parent'    => $parent_id,
				'orderby'        => 'menu_order date',
				'order'          => 'ASC',
				'posts_per_page' => 200,
				'post_status'    => 'any',
			]
		);

		if ( ! is_array( $posts ) ) {
			return [];
		}

		$out = [];
		foreach ( $posts as $post ) {
			if ( $post instanceof WP_Post ) {
				$out[] = $post;
			}
		}
		return $out;
	}
}
