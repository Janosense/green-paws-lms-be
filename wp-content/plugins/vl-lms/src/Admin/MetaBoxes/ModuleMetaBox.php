<?php

declare(strict_types=1);

namespace VL\LMS\Admin\MetaBoxes;

use WP_Post;

/**
 * Meta-box for `vl_module` posts.
 *
 * Three fields: optional intro-video URL, total duration, and a
 * passing-threshold percentage that the runtime uses when surfacing
 * module-level completion.
 *
 * @author Tymofii Synianskyi
 */
class ModuleMetaBox extends AbstractMetaBox {

	public function __construct() {
		parent::__construct( 'vl_module' );
	}

	public function id(): string {
		return 'vl_lms_module';
	}

	public function title(): string {
		return 'Параметри модуля';
	}

	public function render( WP_Post $post ): void {
		$this->render_nonce();
		echo '<div class="vl-lms-meta-box">';
		$this->render_text_row(
			'_vl_module_intro_video_url',
			'Вступне відео (URL)',
			$this->meta_string( $post->ID, '_vl_module_intro_video_url' ),
			'url'
		);
		$this->render_text_row(
			'_vl_module_duration_minutes',
			'Тривалість (хв)',
			(string) $this->meta_int( $post->ID, '_vl_module_duration_minutes' ),
			'number',
			'min="0"'
		);
		$this->render_text_row(
			'_vl_module_passing_threshold',
			'Поріг проходження (%)',
			(string) $this->meta_int( $post->ID, '_vl_module_passing_threshold' ),
			'number',
			'min="0" max="100"'
		);
		echo '</div>';
	}

	public function save( int $post_id, WP_Post $post ): void {
		unset( $post );
		if ( ! $this->verified( $post_id ) ) {
			return;
		}

		$url = $this->post_raw( '_vl_module_intro_video_url' );
		if ( null !== $url ) {
			update_post_meta( $post_id, '_vl_module_intro_video_url', esc_url_raw( $url ) );
		}

		$duration = $this->post_int( '_vl_module_duration_minutes', 0 );
		if ( null !== $duration ) {
			update_post_meta( $post_id, '_vl_module_duration_minutes', $duration );
		}

		$threshold = $this->post_int( '_vl_module_passing_threshold', 0, 100 );
		if ( null !== $threshold ) {
			update_post_meta( $post_id, '_vl_module_passing_threshold', $threshold );
		}
	}
}
