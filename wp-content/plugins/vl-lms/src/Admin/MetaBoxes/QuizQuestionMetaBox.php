<?php

declare(strict_types=1);

namespace VL\LMS\Admin\MetaBoxes;

use WP_Post;

/**
 * Meta-box for `vl_quiz_question` posts.
 *
 * Provides the typed scalar fields (question type, points, media URL,
 * explanation, text-match settings) and the JS-driven answers builder
 * widget. Answers travel as a JSON blob through the
 * `_vl_question_answers_json` hidden field; runtime persistence is on
 * `_vl_question_answers`.
 *
 * @author Tymofii Synianskyi
 */
class QuizQuestionMetaBox extends AbstractMetaBox {

	/** @var list<string> */
	private const array QUESTION_TYPES = [ 'single_choice', 'multiple_choice', 'true_false', 'text' ];

	/** @var list<string> */
	private const array MATCH_MODES = [ 'exact', 'case_insensitive', 'regex' ];

	public function __construct() {
		parent::__construct( 'vl_quiz_question' );
	}

	public function id(): string {
		return 'vl_lms_quiz_question';
	}

	public function title(): string {
		return 'Питання та відповіді';
	}

	public function render( WP_Post $post ): void {
		$this->render_nonce();

		$type = $this->meta_string( $post->ID, '_vl_question_type' );

		$answers_raw = (string) get_post_meta( $post->ID, '_vl_question_answers', true );
		$answers     = [];
		if ( '' !== $answers_raw ) {
			$decoded = json_decode( $answers_raw, true );
			if ( is_array( $decoded ) ) {
				foreach ( $decoded as $row ) {
					if ( ! is_array( $row ) ) {
						continue;
					}
					$answers[] = [
						'id'          => isset( $row['id'] ) ? (string) $row['id'] : '',
						'text'        => isset( $row['text'] ) ? (string) $row['text'] : '',
						'is_correct'  => ! empty( $row['is_correct'] ),
						'explanation' => isset( $row['explanation'] ) ? (string) $row['explanation'] : '',
					];
				}
			}
		}

		echo '<div class="vl-lms-meta-box vl-lms-meta-box--question">';

		$this->render_select_row(
			'_vl_question_type',
			'Тип питання',
			$type,
			[
				'single_choice'   => 'Один варіант',
				'multiple_choice' => 'Декілька варіантів',
				'true_false'      => 'Правда / Неправда',
				'text'            => 'Текстова відповідь',
			]
		);
		$this->render_text_row(
			'_vl_question_points',
			'Бали',
			(string) max( 1, $this->meta_int( $post->ID, '_vl_question_points' ) ),
			'number',
			'min="1"'
		);
		$this->render_text_row(
			'_vl_question_media_url',
			'Медіа (URL)',
			$this->meta_string( $post->ID, '_vl_question_media_url' ),
			'url'
		);
		$this->render_textarea_row(
			'_vl_question_explanation',
			'Пояснення',
			$this->meta_string( $post->ID, '_vl_question_explanation' )
		);

		// Text-only fields. Visibility is JS-driven; rendered always for save.
		$this->render_select_row(
			'_vl_question_match_mode',
			'Режим зіставлення (текст)',
			$this->meta_string( $post->ID, '_vl_question_match_mode' ),
			[
				'exact'            => 'Точний',
				'case_insensitive' => 'Без врахування регістру',
				'regex'            => 'Регулярний вираз',
			]
		);
		$this->render_text_row(
			'_vl_question_correct_text',
			'Правильна відповідь (текст)',
			$this->meta_string( $post->ID, '_vl_question_correct_text' )
		);

		// Answers builder.
		echo '<div class="vl-lms-row vl-lms-row--full vl-lms-answers" data-question-type="' . esc_attr( $type ) . '">';
		echo '<label>Відповіді</label>';
		echo '<ul class="vl-lms-answers-list">';
		foreach ( $answers as $answer ) {
			$this->render_answer_row( $answer );
		}
		echo '</ul>';
		echo '<button type="button" class="button vl-lms-answer-add">Додати відповідь</button>';
		printf(
			'<input type="hidden" name="_vl_question_answers_json" value=\'%s\' />',
			esc_attr( (string) wp_json_encode( $answers ) )
		);
		echo '</div>';

		echo '</div>';
	}

	/**
	 * @param array{id: string, text: string, is_correct: bool, explanation: string} $answer
	 */
	private function render_answer_row( array $answer ): void {
		printf(
			'<li class="vl-lms-answer-item" data-answer-id="%1$s">'
			. '<input type="text" class="vl-lms-answer-text" value="%2$s" placeholder="Текст відповіді" />'
			. '<label class="vl-lms-answer-correct"><input type="checkbox" %3$s /> Правильна</label>'
			. '<input type="text" class="vl-lms-answer-explanation" value="%4$s" placeholder="Пояснення" />'
			. '<button type="button" class="button-link vl-lms-answer-remove">Видалити</button>'
			. '</li>',
			esc_attr( $answer['id'] ),
			esc_attr( $answer['text'] ),
			$answer['is_correct'] ? 'checked' : '',
			esc_attr( $answer['explanation'] )
		);
	}

	public function save( int $post_id, WP_Post $post ): void {
		unset( $post );
		if ( ! $this->verified( $post_id ) ) {
			return;
		}

		$type = $this->post_enum( '_vl_question_type', self::QUESTION_TYPES );
		if ( null !== $type ) {
			update_post_meta( $post_id, '_vl_question_type', $type );
		}

		$points = $this->post_int( '_vl_question_points', 1 );
		if ( null !== $points ) {
			update_post_meta( $post_id, '_vl_question_points', $points );
		}

		$media = $this->post_raw( '_vl_question_media_url' );
		if ( null !== $media ) {
			update_post_meta( $post_id, '_vl_question_media_url', esc_url_raw( $media ) );
		}

		$explanation = $this->post_raw( '_vl_question_explanation' );
		if ( null !== $explanation ) {
			update_post_meta( $post_id, '_vl_question_explanation', wp_kses_post( $explanation ) );
		}

		$match_mode = $this->post_enum( '_vl_question_match_mode', self::MATCH_MODES );
		if ( null !== $match_mode ) {
			update_post_meta( $post_id, '_vl_question_match_mode', $match_mode );
		}

		$correct_text = $this->post_string( '_vl_question_correct_text' );
		if ( null !== $correct_text ) {
			update_post_meta( $post_id, '_vl_question_correct_text', $correct_text );
		}

		$answers_json = $this->post_raw( '_vl_question_answers_json' );
		if ( null !== $answers_json ) {
			$decoded = json_decode( $answers_json, true );
			$items   = [];
			if ( is_array( $decoded ) ) {
				foreach ( $decoded as $row ) {
					if ( ! is_array( $row ) ) {
						continue;
					}
					$id   = isset( $row['id'] ) ? sanitize_text_field( (string) $row['id'] ) : '';
					$text = isset( $row['text'] ) ? sanitize_text_field( (string) $row['text'] ) : '';
					if ( '' === $id ) {
						continue;
					}
					$items[] = [
						'id'          => $id,
						'text'        => $text,
						'is_correct'  => ! empty( $row['is_correct'] ),
						'explanation' => isset( $row['explanation'] ) ? wp_kses_post( (string) $row['explanation'] ) : '',
					];
				}
			}
			update_post_meta( $post_id, '_vl_question_answers', wp_json_encode( $items ) );
		}
	}
}
