<?php

declare(strict_types=1);

namespace VL\LMS\Admin\MetaBoxes;

use WP_Post;

/**
 * Meta-box for `vl_quiz` posts.
 *
 * Quiz fields cover the time and attempt limits, the passing
 * threshold, two shuffle flags, and the "show correct answers" policy.
 *
 * @author Tymofii Synianskyi
 */
class QuizMetaBox extends AbstractMetaBox {

	/** @var list<string> */
	private const array SHOW_CORRECT_OPTIONS = [ 'never', 'after_submit', 'after_pass' ];

	public function __construct() {
		parent::__construct( 'vl_quiz' );
	}

	public function id(): string {
		return 'vl_lms_quiz';
	}

	public function title(): string {
		return 'Параметри тесту';
	}

	public function render( WP_Post $post ): void {
		$this->render_nonce();
		echo '<div class="vl-lms-meta-box">';

		$this->render_text_row(
			'_vl_quiz_time_limit_seconds',
			'Ліміт часу (сек, 0 = без обмеження)',
			(string) $this->meta_int( $post->ID, '_vl_quiz_time_limit_seconds' ),
			'number',
			'min="0"'
		);
		$this->render_text_row(
			'_vl_quiz_max_attempts',
			'Макс. спроб (0 = необмежено)',
			(string) $this->meta_int( $post->ID, '_vl_quiz_max_attempts' ),
			'number',
			'min="0"'
		);
		$this->render_text_row(
			'_vl_quiz_passing_threshold',
			'Поріг проходження (%)',
			(string) $this->meta_int( $post->ID, '_vl_quiz_passing_threshold' ),
			'number',
			'min="0" max="100"'
		);
		$this->render_checkbox_row(
			'_vl_quiz_shuffle_questions',
			'Перемішувати питання',
			$this->meta_bool( $post->ID, '_vl_quiz_shuffle_questions' )
		);
		$this->render_checkbox_row(
			'_vl_quiz_shuffle_answers',
			'Перемішувати відповіді',
			$this->meta_bool( $post->ID, '_vl_quiz_shuffle_answers' )
		);
		$this->render_select_row(
			'_vl_quiz_show_correct_answers',
			'Показ правильних відповідей',
			$this->meta_string( $post->ID, '_vl_quiz_show_correct_answers' ),
			[
				'never'        => 'Ніколи',
				'after_submit' => 'Після відправки',
				'after_pass'   => 'Після успішного проходження',
			]
		);
		$this->render_checkbox_row(
			'_vl_quiz_is_final_exam',
			'Це фінальний іспит',
			$this->meta_bool( $post->ID, '_vl_quiz_is_final_exam' )
		);

		echo '</div>';
	}

	public function save( int $post_id, WP_Post $post ): void {
		unset( $post );
		if ( ! $this->verified( $post_id ) ) {
			return;
		}

		$time_limit = $this->post_int( '_vl_quiz_time_limit_seconds', 0 );
		if ( null !== $time_limit ) {
			update_post_meta( $post_id, '_vl_quiz_time_limit_seconds', $time_limit );
		}

		$max_attempts = $this->post_int( '_vl_quiz_max_attempts', 0 );
		if ( null !== $max_attempts ) {
			update_post_meta( $post_id, '_vl_quiz_max_attempts', $max_attempts );
		}

		$threshold = $this->post_int( '_vl_quiz_passing_threshold', 0, 100 );
		if ( null !== $threshold ) {
			update_post_meta( $post_id, '_vl_quiz_passing_threshold', $threshold );
		}

		update_post_meta(
			$post_id,
			'_vl_quiz_shuffle_questions',
			$this->post_checkbox( '_vl_quiz_shuffle_questions' )
		);
		update_post_meta(
			$post_id,
			'_vl_quiz_shuffle_answers',
			$this->post_checkbox( '_vl_quiz_shuffle_answers' )
		);

		$show_correct = $this->post_enum( '_vl_quiz_show_correct_answers', self::SHOW_CORRECT_OPTIONS );
		if ( null !== $show_correct ) {
			update_post_meta( $post_id, '_vl_quiz_show_correct_answers', $show_correct );
		}

		update_post_meta(
			$post_id,
			'_vl_quiz_is_final_exam',
			$this->post_checkbox( '_vl_quiz_is_final_exam' )
		);
	}
}
