<?php

declare(strict_types=1);

namespace VL\LMS\Admin\MetaBoxes;

use WP_Post;

/**
 * Meta-box for `vl_topic` posts.
 *
 * Topics carry only video metadata — content blocks live in the post
 * editor itself and attachments belong to lessons.
 *
 * @author Tymofii Synianskyi
 */
class TopicMetaBox extends AbstractMetaBox {

	/** @var list<string> */
	private const array PROVIDERS = [ 'vimeo', 'youtube', 'file', 'embed' ];

	public function __construct() {
		parent::__construct( 'vl_topic' );
	}

	public function id(): string {
		return 'vl_lms_topic';
	}

	public function title(): string {
		return 'Параметри теми';
	}

	public function render( WP_Post $post ): void {
		$this->render_nonce();
		echo '<div class="vl-lms-meta-box">';

		$this->render_select_row(
			'_vl_topic_video_provider',
			'Провайдер відео',
			$this->meta_string( $post->ID, '_vl_topic_video_provider' ),
			[
				'vimeo'   => 'Vimeo',
				'youtube' => 'YouTube',
				'file'    => 'Файл',
				'embed'   => 'Embed',
			]
		);
		$this->render_text_row(
			'_vl_topic_video_url',
			'URL відео',
			$this->meta_string( $post->ID, '_vl_topic_video_url' ),
			'url'
		);
		$this->render_text_row(
			'_vl_topic_duration_seconds',
			'Тривалість (сек)',
			(string) $this->meta_int( $post->ID, '_vl_topic_duration_seconds' ),
			'number',
			'min="0"'
		);

		echo '</div>';
	}

	public function save( int $post_id, WP_Post $post ): void {
		unset( $post );
		if ( ! $this->verified( $post_id ) ) {
			return;
		}

		$provider = $this->post_enum( '_vl_topic_video_provider', self::PROVIDERS );
		if ( null !== $provider ) {
			update_post_meta( $post_id, '_vl_topic_video_provider', $provider );
		}

		$url = $this->post_raw( '_vl_topic_video_url' );
		if ( null !== $url ) {
			update_post_meta( $post_id, '_vl_topic_video_url', esc_url_raw( $url ) );
		}

		$duration = $this->post_int( '_vl_topic_duration_seconds', 0 );
		if ( null !== $duration ) {
			update_post_meta( $post_id, '_vl_topic_duration_seconds', $duration );
		}
	}
}
