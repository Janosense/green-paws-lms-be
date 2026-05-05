<?php

declare(strict_types=1);

namespace VL\LMS\Admin\MetaBoxes;

use WP_Post;

/**
 * Meta-box for `vl_lesson` posts.
 *
 * Fields cover the video provider/URL pair, total duration, two flags
 * (`is_preview`, `requires_completion`), and the JSON-shaped attachment
 * list that the lesson-content REST controller surfaces to the frontend.
 *
 * @author Tymofii Synianskyi
 */
class LessonMetaBox extends AbstractMetaBox {

	/** @var list<string> */
	private const array PROVIDERS = [ 'vimeo', 'youtube', 'file', 'embed' ];

	public function __construct() {
		parent::__construct( 'vl_lesson' );
	}

	public function id(): string {
		return 'vl_lms_lesson';
	}

	public function title(): string {
		return 'Параметри уроку';
	}

	public function render( WP_Post $post ): void {
		$this->render_nonce();
		echo '<div class="vl-lms-meta-box">';

		$this->render_select_row(
			'_vl_lesson_video_provider',
			'Провайдер відео',
			$this->meta_string( $post->ID, '_vl_lesson_video_provider' ),
			[
				'vimeo'   => 'Vimeo',
				'youtube' => 'YouTube',
				'file'    => 'Файл',
				'embed'   => 'Embed',
			]
		);
		$this->render_text_row(
			'_vl_lesson_video_url',
			'URL відео',
			$this->meta_string( $post->ID, '_vl_lesson_video_url' ),
			'url'
		);
		$this->render_text_row(
			'_vl_lesson_duration_seconds',
			'Тривалість (сек)',
			(string) $this->meta_int( $post->ID, '_vl_lesson_duration_seconds' ),
			'number',
			'min="0"'
		);
		$this->render_checkbox_row(
			'_vl_lesson_is_preview',
			'Безкоштовний прев\'ю',
			$this->meta_bool( $post->ID, '_vl_lesson_is_preview' )
		);
		$this->render_checkbox_row(
			'_vl_lesson_requires_completion',
			'Обов\'язковий для проходження',
			$this->meta_bool( $post->ID, '_vl_lesson_requires_completion' )
		);

		$this->render_attachment_list_widget( $post->ID, '_vl_lesson_attachments', 'Вкладення' );

		echo '</div>';
	}

	public function save( int $post_id, WP_Post $post ): void {
		unset( $post );
		if ( ! $this->verified( $post_id ) ) {
			return;
		}

		$provider = $this->post_enum( '_vl_lesson_video_provider', self::PROVIDERS );
		if ( null !== $provider ) {
			update_post_meta( $post_id, '_vl_lesson_video_provider', $provider );
		}

		$url = $this->post_raw( '_vl_lesson_video_url' );
		if ( null !== $url ) {
			update_post_meta( $post_id, '_vl_lesson_video_url', esc_url_raw( $url ) );
		}

		$duration = $this->post_int( '_vl_lesson_duration_seconds', 0 );
		if ( null !== $duration ) {
			update_post_meta( $post_id, '_vl_lesson_duration_seconds', $duration );
		}

		update_post_meta( $post_id, '_vl_lesson_is_preview', $this->post_checkbox( '_vl_lesson_is_preview' ) );
		update_post_meta(
			$post_id,
			'_vl_lesson_requires_completion',
			$this->post_checkbox( '_vl_lesson_requires_completion' )
		);

		$attachments_json = $this->sanitize_attachment_list_post( '_vl_lesson_attachments' );
		if ( null !== $attachments_json ) {
			update_post_meta( $post_id, '_vl_lesson_attachments', $attachments_json );
		}
	}
}
