<?php

declare(strict_types=1);

namespace VL\LMS\Admin\MetaBoxes\ChildList;

use WP_Post;

/**
 * Sortable question list rendered on the `vl_quiz` edit screen.
 *
 * Overrides {@see AbstractChildListMetaBox::render()} to add an edit
 * link on every row and an "Add new" button under the list. Questions
 * are hidden from the wp-admin menu (`vl_quiz_question` has
 * `show_in_menu: false`), so without these affordances the only way to
 * create or edit a question is to type the post URL by hand. The
 * "Додати питання" button carries `vl_parent_id=<quiz_id>`, which
 * {@see \VL\LMS\Admin\MetaBoxes\QuizQuestionMetaBox} reads to pre-bind
 * the new question to this quiz.
 *
 * @author Tymofii Synianskyi
 */
class QuestionListMetaBox extends AbstractChildListMetaBox {

	public function __construct() {
		parent::__construct( 'vl_quiz', 'vl_quiz_question' );
	}

	public function id(): string {
		return 'vl_lms_question_list';
	}

	public function title(): string {
		return 'Питання';
	}

	public function render( WP_Post $post ): void {
		$children    = $this->query_children( (int) $post->ID );
		$add_new_url = add_query_arg(
			[
				'post_type'    => 'vl_quiz_question',
				'vl_parent_id' => (int) $post->ID,
			],
			admin_url( 'post-new.php' )
		);

		if ( [] === $children ) {
			echo '<p class="vl-lms-empty">Немає елементів</p>';
		} else {
			printf(
				'<ul class="vl-sortable-list" data-parent-id="%1$d" data-nonce="%2$s">',
				(int) $post->ID,
				esc_attr( wp_create_nonce( 'vl_lms_reorder' ) )
			);
			foreach ( $children as $child ) {
				$title     = '' !== $child->post_title ? $child->post_title : sprintf( '#%d', (int) $child->ID );
				$edit_link = (string) get_edit_post_link( (int) $child->ID );
				printf(
					'<li data-id="%1$d">'
					. '<span class="vl-sortable-handle">⋮⋮</span> '
					. '<span class="vl-sortable-title">%2$s</span> '
					. '<a class="vl-sortable-edit" href="%3$s">%4$s</a>'
					. '</li>',
					(int) $child->ID,
					esc_html( $title ),
					esc_url( $edit_link ),
					esc_html__( 'Редагувати', 'vl-lms' )
				);
			}
			echo '</ul>';
		}

		printf(
			'<p class="vl-lms-add-new"><a class="button" href="%1$s">%2$s</a></p>',
			esc_url( $add_new_url ),
			esc_html__( 'Додати питання', 'vl-lms' )
		);
	}
}
