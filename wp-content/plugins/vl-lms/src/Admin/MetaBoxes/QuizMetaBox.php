<?php

declare(strict_types=1);

namespace VL\LMS\Admin\MetaBoxes;

use WP_Post;
use WP_Query;

/**
 * Meta-box for `vl_quiz` posts.
 *
 * Quiz fields cover the time and attempt limits, the passing
 * threshold, two shuffle flags, the "show correct answers" policy, and
 * the two progression-gating flags.
 *
 * The gating flags are the only settings here that affect access to
 * *other* posts: `_vl_quiz_blocks_progression` locks every later
 * curriculum entity until this quiz is passed, and
 * `_vl_quiz_requires_all_quizzes_passed` locks this quiz until the rest
 * of the course's non-final quizzes are passed. Both default off, so an
 * untouched quiz gates nothing. Because a misconfigured gate can strand
 * a learner rather than merely annoy them,
 * {@see self::render_progression_warnings()} surfaces the two
 * unsatisfiable combinations at edit time.
 *
 * @author Tymofii Synianskyi
 */
class QuizMetaBox extends AbstractMetaBox {

	use CurriculumParentPickerTrait;

	/** @var list<string> */
	private const array SHOW_CORRECT_OPTIONS = [ 'never', 'after_submit', 'after_pass' ];

	private const string PARENT_PREFIX = '_vl_quiz_parent';

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

		$this->render_curriculum_parent_picker( $post, self::PARENT_PREFIX, 'quiz' );

		$this->render_section_heading( 'Параметри' );

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

		$this->render_section_heading( 'Доступ і послідовність' );

		$this->render_checkbox_row(
			'_vl_quiz_blocks_progression',
			'Блокувати подальше проходження, доки тест не складено',
			$this->meta_bool( $post->ID, '_vl_quiz_blocks_progression' )
		);
		$this->render_checkbox_row(
			'_vl_quiz_requires_all_quizzes_passed',
			'Доступний лише після складання всіх тестів курсу',
			$this->meta_bool( $post->ID, '_vl_quiz_requires_all_quizzes_passed' )
		);

		$this->render_progression_warnings( $post );

		echo '</div>';
	}

	/**
	 * Warns about setting combinations that can strand a learner.
	 *
	 * A blocking quiz is the only place in the LMS where failing to satisfy
	 * a condition removes access to *later* content, so the two ways of
	 * making that condition unsatisfiable both deserve a heads-up at edit
	 * time rather than a support ticket later:
	 *
	 *  - a finite `_vl_quiz_max_attempts` — spend them without passing and
	 *    the course is over, with no in-app way back (the escape hatch is
	 *    `wp vl-lms quiz reset-attempts`);
	 *  - zero questions — {@see \VL\LMS\Quiz\QuizAttemptService} scores a
	 *    `max_score` of 0 as `passed = false` unconditionally, so the gate
	 *    can never open at all.
	 *
	 * Both are advisory only. An editor may legitimately want a one-shot
	 * exam, and a quiz is routinely saved before its questions are written.
	 */
	private function render_progression_warnings( WP_Post $post ): void {
		if ( ! $this->meta_bool( $post->ID, '_vl_quiz_blocks_progression' ) ) {
			return;
		}

		$max_attempts = $this->meta_int( $post->ID, '_vl_quiz_max_attempts' );
		if ( $max_attempts > 0 ) {
			$this->render_inline_notice(
				sprintf(
					'Тест блокує проходження курсу та має ліміт спроб (%d). '
					. 'Студент, який вичерпає спроби не склавши тест, не зможе продовжити курс. '
					. 'Скинути спроби можна командою «wp vl-lms quiz reset-attempts».',
					$max_attempts
				)
			);
		}

		if ( 0 === $this->count_questions( (int) $post->ID ) ) {
			$this->render_inline_notice(
				'Тест блокує проходження курсу, але не містить жодного питання. '
				. 'Такий тест неможливо скласти — додайте питання або зніміть блокування.'
			);
		}
	}

	/**
	 * Published question count for the quiz. Isolated so tests can override
	 * without instantiating `WP_Query`.
	 */
	protected function count_questions( int $quiz_id ): int {
		$query = new WP_Query(
			[
				'post_type'              => 'vl_quiz_question',
				'post_parent'            => $quiz_id,
				'post_status'            => 'publish',
				'fields'                 => 'ids',
				'posts_per_page'         => 1,
				'no_found_rows'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			]
		);

		return (int) $query->found_posts;
	}

	public function save( int $post_id, WP_Post $post ): void {
		unset( $post );
		if ( ! $this->verified( $post_id ) ) {
			return;
		}

		$this->save_curriculum_parent( $post_id, self::PARENT_PREFIX );

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

		update_post_meta(
			$post_id,
			'_vl_quiz_blocks_progression',
			$this->post_checkbox( '_vl_quiz_blocks_progression' )
		);
		update_post_meta(
			$post_id,
			'_vl_quiz_requires_all_quizzes_passed',
			$this->post_checkbox( '_vl_quiz_requires_all_quizzes_passed' )
		);
	}
}
