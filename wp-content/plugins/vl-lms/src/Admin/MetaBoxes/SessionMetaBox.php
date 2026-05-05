<?php

declare(strict_types=1);

namespace VL\LMS\Admin\MetaBoxes;

use WP_Post;

/**
 * Meta-box for `vl_session` posts.
 *
 * Editable fields cover the cohort session schedule, status, recording
 * window, and instructor-uploaded materials. Zoom-managed fields
 * (`_vl_session_zoom_*`, `_vl_session_recording_url`) are rendered as
 * read-only displays so authors see the synced state without being able
 * to overwrite values produced by
 * {@see \VL\LMS\Services\Zoom\Sync\MeetingSynchronizer} or the recording
 * webhook handler.
 *
 * @author Tymofii Synianskyi
 */
class SessionMetaBox extends AbstractMetaBox {

	/** @var list<string> */
	private const array STATUSES = [ 'scheduled', 'live', 'completed', 'cancelled' ];

	public function __construct() {
		parent::__construct( 'vl_session' );
	}

	public function id(): string {
		return 'vl_lms_session';
	}

	public function title(): string {
		return 'Параметри сесії';
	}

	public function render( WP_Post $post ): void {
		$this->render_nonce();
		echo '<div class="vl-lms-meta-box">';

		$this->render_section_heading( 'Розклад' );
		$this->render_text_row(
			'_vl_session_number',
			'Номер сесії',
			(string) $this->meta_int( $post->ID, '_vl_session_number' ),
			'number',
			'min="1"'
		);
		$this->render_text_row(
			'_vl_session_scheduled_start',
			'Запланований початок',
			$this->meta_string( $post->ID, '_vl_session_scheduled_start' ),
			'datetime-local'
		);
		$this->render_text_row(
			'_vl_session_scheduled_end',
			'Запланований кінець',
			$this->meta_string( $post->ID, '_vl_session_scheduled_end' ),
			'datetime-local'
		);
		$this->render_select_row(
			'_vl_session_status',
			'Статус',
			$this->meta_string( $post->ID, '_vl_session_status' ),
			[
				'scheduled' => 'Заплановано',
				'live'      => 'Йде зараз',
				'completed' => 'Завершено',
				'cancelled' => 'Скасовано',
			]
		);
		$this->render_text_row(
			'_vl_session_recording_available_until',
			'Запис доступний до',
			$this->meta_string( $post->ID, '_vl_session_recording_available_until' ),
			'datetime-local'
		);

		$this->render_attachment_list_widget( $post->ID, '_vl_session_materials', 'Матеріали сесії' );

		$this->render_section_heading( 'Zoom (заповнюється автоматично)' );
		$this->render_readonly_row(
			'Meeting ID',
			$this->meta_string( $post->ID, '_vl_session_zoom_meeting_id' )
		);
		$this->render_readonly_row(
			'Join URL',
			$this->meta_string( $post->ID, '_vl_session_zoom_join_url' )
		);
		$this->render_readonly_row(
			'Start URL',
			$this->meta_string( $post->ID, '_vl_session_zoom_start_url' )
		);
		$this->render_readonly_row(
			'Password',
			$this->meta_string( $post->ID, '_vl_session_zoom_password' )
		);
		$this->render_readonly_row(
			'Recording URL',
			$this->meta_string( $post->ID, '_vl_session_recording_url' )
		);

		echo '</div>';
	}

	public function save( int $post_id, WP_Post $post ): void {
		unset( $post );
		if ( ! $this->verified( $post_id ) ) {
			return;
		}

		$number = $this->post_int( '_vl_session_number', 0 );
		if ( null !== $number ) {
			update_post_meta( $post_id, '_vl_session_number', $number );
		}

		$datetime_keys = [
			'_vl_session_scheduled_start',
			'_vl_session_scheduled_end',
			'_vl_session_recording_available_until',
		];
		foreach ( $datetime_keys as $key ) {
			$value = $this->post_string( $key );
			if ( null !== $value && '' !== $value ) {
				update_post_meta( $post_id, $key, $value );
			} elseif ( null !== $value ) {
				update_post_meta( $post_id, $key, '' );
			}
		}

		$status = $this->post_enum( '_vl_session_status', self::STATUSES );
		if ( null !== $status ) {
			update_post_meta( $post_id, '_vl_session_status', $status );
		}

		$materials_json = $this->sanitize_attachment_list_post( '_vl_session_materials' );
		if ( null !== $materials_json ) {
			update_post_meta( $post_id, '_vl_session_materials', $materials_json );
		}
	}
}
