<?php

declare(strict_types=1);

namespace VL\LMS\Admin\MetaBoxes;

use WP_Post;

/**
 * Meta-box for `vl_assignment` posts.
 *
 * Editable: submission type, max/passing score, two required-input
 * flags, an optional due-date offset, and a free-form rubric (kept
 * through `wp_kses_post()` on save).
 *
 * @author Tymofii Synianskyi
 */
class AssignmentMetaBox extends AbstractMetaBox {

	use CurriculumParentPickerTrait;

	/** @var list<string> */
	private const array SUBMISSION_TYPES = [ 'text', 'file', 'both' ];

	private const string PARENT_PREFIX = '_vl_assignment_parent';

	public function __construct() {
		parent::__construct( 'vl_assignment' );
	}

	public function id(): string {
		return 'vl_lms_assignment';
	}

	public function title(): string {
		return 'Параметри завдання';
	}

	public function render( WP_Post $post ): void {
		$this->render_nonce();
		echo '<div class="vl-lms-meta-box">';

		$this->render_curriculum_parent_picker( $post, self::PARENT_PREFIX, 'assignment' );

		$this->render_section_heading( 'Параметри' );

		$this->render_select_row(
			'_vl_assignment_submission_type',
			'Тип здачі',
			$this->meta_string( $post->ID, '_vl_assignment_submission_type' ),
			[
				'text' => 'Текст',
				'file' => 'Файл',
				'both' => 'Текст і файл',
			]
		);
		$this->render_text_row(
			'_vl_assignment_max_score',
			'Макс. бал',
			(string) max( 1, $this->meta_int( $post->ID, '_vl_assignment_max_score' ) ),
			'number',
			'min="1"'
		);
		$this->render_text_row(
			'_vl_assignment_passing_score',
			'Прохідний бал',
			(string) $this->meta_int( $post->ID, '_vl_assignment_passing_score' ),
			'number',
			'min="0"'
		);
		$this->render_checkbox_row(
			'_vl_assignment_text_required',
			'Текст обов\'язковий',
			$this->meta_bool( $post->ID, '_vl_assignment_text_required' )
		);
		$this->render_checkbox_row(
			'_vl_assignment_file_required',
			'Файл обов\'язковий',
			$this->meta_bool( $post->ID, '_vl_assignment_file_required' )
		);
		$this->render_text_row(
			'_vl_assignment_due_days_after_enrollment',
			'Дедлайн (днів після зарахування, 0 = без)',
			(string) $this->meta_int( $post->ID, '_vl_assignment_due_days_after_enrollment' ),
			'number',
			'min="0"'
		);
		$this->render_textarea_row(
			'_vl_assignment_rubric',
			'Критерії оцінювання',
			$this->meta_string( $post->ID, '_vl_assignment_rubric' ),
			6
		);

		echo '</div>';
	}

	public function save( int $post_id, WP_Post $post ): void {
		unset( $post );
		if ( ! $this->verified( $post_id ) ) {
			return;
		}

		$this->save_curriculum_parent( $post_id, self::PARENT_PREFIX );

		$type = $this->post_enum( '_vl_assignment_submission_type', self::SUBMISSION_TYPES );
		if ( null !== $type ) {
			update_post_meta( $post_id, '_vl_assignment_submission_type', $type );
		}

		$max = $this->post_int( '_vl_assignment_max_score', 1 );
		if ( null !== $max ) {
			update_post_meta( $post_id, '_vl_assignment_max_score', $max );
		}

		$passing = $this->post_int( '_vl_assignment_passing_score', 0 );
		if ( null !== $passing ) {
			update_post_meta( $post_id, '_vl_assignment_passing_score', $passing );
		}

		update_post_meta(
			$post_id,
			'_vl_assignment_text_required',
			$this->post_checkbox( '_vl_assignment_text_required' )
		);
		update_post_meta(
			$post_id,
			'_vl_assignment_file_required',
			$this->post_checkbox( '_vl_assignment_file_required' )
		);

		$due = $this->post_int( '_vl_assignment_due_days_after_enrollment', 0 );
		if ( null !== $due ) {
			update_post_meta( $post_id, '_vl_assignment_due_days_after_enrollment', $due );
		}

		$rubric = $this->post_raw( '_vl_assignment_rubric' );
		if ( null !== $rubric ) {
			update_post_meta( $post_id, '_vl_assignment_rubric', wp_kses_post( $rubric ) );
		}
	}
}
