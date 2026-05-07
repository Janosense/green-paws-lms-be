<?php

declare(strict_types=1);

namespace VL\LMS\Admin\MetaBoxes;

use WP_Post;

/**
 * Meta-box for `vl_webinar` posts.
 *
 * Webinar fields mirror the {@see \VL\LMS\CPT\WebinarType} schema —
 * scheduling, pricing, registration window, recording-access window,
 * promotional cover, and instructor-uploaded materials. The Zoom-side
 * keys (`_vl_webinar_zoom_*`, `_vl_webinar_recording_url`,
 * `_vl_webinar_recording_available_until`) are read-only displays
 * because they are written by the meeting synchronizer and the
 * recording webhook.
 *
 * @author Tymofii Synianskyi
 */
class WebinarMetaBox extends AbstractMetaBox {

	/** @var list<string> */
	private const array STATUSES = [ 'scheduled', 'live', 'completed', 'cancelled' ];

	public function __construct() {
		parent::__construct( 'vl_webinar' );
	}

	public function id(): string {
		return 'vl_lms_webinar';
	}

	public function title(): string {
		return 'Параметри вебінару';
	}

	public function render( WP_Post $post ): void {
		$this->render_nonce();

		$cover_id    = $this->meta_int( $post->ID, '_vl_webinar_cover_image_id' );
		$cover_thumb = '';
		if ( $cover_id > 0 ) {
			$thumb = wp_get_attachment_image( $cover_id, 'thumbnail', false, [ 'class' => 'vl-lms-cover-thumb' ] );
			if ( is_string( $thumb ) ) {
				$cover_thumb = $thumb;
			}
		}

		echo '<div class="vl-lms-meta-box">';

		$this->render_section_heading( 'Розклад' );
		$this->render_text_row(
			'_vl_webinar_scheduled_start',
			'Запланований початок',
			$this->iso8601_to_datetime_local( $this->meta_string( $post->ID, '_vl_webinar_scheduled_start' ) ),
			'datetime-local'
		);
		$this->render_text_row(
			'_vl_webinar_scheduled_end',
			'Запланований кінець',
			$this->iso8601_to_datetime_local( $this->meta_string( $post->ID, '_vl_webinar_scheduled_end' ) ),
			'datetime-local'
		);
		$this->render_select_row(
			'_vl_webinar_status',
			'Статус',
			$this->meta_string( $post->ID, '_vl_webinar_status' ),
			[
				'scheduled' => 'Заплановано',
				'live'      => 'Йде зараз',
				'completed' => 'Завершено',
				'cancelled' => 'Скасовано',
			]
		);

		$this->render_section_heading( 'Реєстрація та ціна' );
		$this->render_text_row(
			'_vl_webinar_price',
			'Ціна',
			(string) $this->meta_float( $post->ID, '_vl_webinar_price' ),
			'number',
			'min="0" step="0.01"'
		);
		$this->render_text_row(
			'_vl_webinar_currency',
			'Валюта',
			$this->meta_string( $post->ID, '_vl_webinar_currency' ),
			'text',
			'maxlength="3"'
		);
		$this->render_text_row(
			'_vl_webinar_max_attendees',
			'Макс. учасників (0 = необмежено)',
			(string) $this->meta_int( $post->ID, '_vl_webinar_max_attendees' ),
			'number',
			'min="0"'
		);
		$this->render_text_row(
			'_vl_webinar_registration_opens_at',
			'Реєстрація відкривається',
			$this->iso8601_to_datetime_local( $this->meta_string( $post->ID, '_vl_webinar_registration_opens_at' ) ),
			'datetime-local'
		);
		$this->render_text_row(
			'_vl_webinar_registration_closes_at',
			'Реєстрація закривається',
			$this->iso8601_to_datetime_local( $this->meta_string( $post->ID, '_vl_webinar_registration_closes_at' ) ),
			'datetime-local'
		);
		$this->render_text_row(
			'_vl_webinar_recording_access_days',
			'Доступ до запису (днів, 0 = не надається)',
			(string) $this->meta_int( $post->ID, '_vl_webinar_recording_access_days' ),
			'number',
			'min="0"'
		);

		$this->render_section_heading( 'Промо' );
		$this->render_text_row(
			'_vl_webinar_preview_video_url',
			'Промо-відео (URL)',
			$this->meta_string( $post->ID, '_vl_webinar_preview_video_url' ),
			'url'
		);
		echo '<div class="vl-lms-row vl-lms-row--media"><label for="_vl_webinar_cover_image_id">Обкладинка</label>';
		printf(
			'<div class="vl-lms-media-control" data-target="_vl_webinar_cover_image_id">'
			. '<span class="vl-lms-media-thumb">%1$s</span>'
			. '<input type="hidden" id="_vl_webinar_cover_image_id" name="_vl_webinar_cover_image_id" value="%2$d" />'
			. '<button type="button" class="button vl-lms-media-pick">Обрати</button>'
			. '<button type="button" class="button-link vl-lms-media-clear">Очистити</button>'
			. '</div>',
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_get_attachment_image returns escaped HTML.
			$cover_thumb,
			(int) $cover_id
		);
		echo '</div>';

		$this->render_attachment_list_widget( $post->ID, '_vl_webinar_materials', 'Матеріали вебінару' );

		$this->render_section_heading( 'Zoom (заповнюється автоматично)' );
		$this->render_readonly_row(
			'Meeting ID',
			$this->meta_string( $post->ID, '_vl_webinar_zoom_meeting_id' )
		);
		$this->render_readonly_row(
			'Join URL',
			$this->meta_string( $post->ID, '_vl_webinar_zoom_join_url' )
		);
		$this->render_readonly_row(
			'Start URL',
			$this->meta_string( $post->ID, '_vl_webinar_zoom_start_url' )
		);
		$this->render_readonly_row(
			'Password',
			$this->meta_string( $post->ID, '_vl_webinar_zoom_password' )
		);
		$this->render_readonly_row(
			'Recording URL',
			$this->meta_string( $post->ID, '_vl_webinar_recording_url' )
		);
		$this->render_readonly_row(
			'Запис доступний до',
			$this->meta_string( $post->ID, '_vl_webinar_recording_available_until' )
		);

		echo '</div>';
	}

	public function save( int $post_id, WP_Post $post ): void {
		unset( $post );
		if ( ! $this->verified( $post_id ) ) {
			return;
		}

		$datetime_keys = [
			'_vl_webinar_scheduled_start',
			'_vl_webinar_scheduled_end',
			'_vl_webinar_registration_opens_at',
			'_vl_webinar_registration_closes_at',
		];
		foreach ( $datetime_keys as $key ) {
			$value = $this->post_string( $key );
			if ( null === $value ) {
				continue;
			}
			update_post_meta( $post_id, $key, $this->datetime_local_to_iso8601( $value ) );
		}

		$status = $this->post_enum( '_vl_webinar_status', self::STATUSES );
		if ( null !== $status ) {
			update_post_meta( $post_id, '_vl_webinar_status', $status );
		}

		$price = $this->post_float( '_vl_webinar_price', 0.0 );
		if ( null !== $price ) {
			update_post_meta( $post_id, '_vl_webinar_price', $price );
		}

		$currency = $this->post_string( '_vl_webinar_currency' );
		if ( null !== $currency ) {
			update_post_meta(
				$post_id,
				'_vl_webinar_currency',
				strtoupper( substr( $currency, 0, 3 ) )
			);
		}

		$max_attendees = $this->post_int( '_vl_webinar_max_attendees', 0 );
		if ( null !== $max_attendees ) {
			update_post_meta( $post_id, '_vl_webinar_max_attendees', $max_attendees );
		}

		$recording_days = $this->post_int( '_vl_webinar_recording_access_days', 0 );
		if ( null !== $recording_days ) {
			update_post_meta( $post_id, '_vl_webinar_recording_access_days', $recording_days );
		}

		$preview = $this->post_raw( '_vl_webinar_preview_video_url' );
		if ( null !== $preview ) {
			update_post_meta( $post_id, '_vl_webinar_preview_video_url', esc_url_raw( $preview ) );
		}

		$cover = $this->post_int( '_vl_webinar_cover_image_id', 0 );
		if ( null !== $cover ) {
			update_post_meta( $post_id, '_vl_webinar_cover_image_id', $cover );
		}

		$materials_json = $this->sanitize_attachment_list_post( '_vl_webinar_materials' );
		if ( null !== $materials_json ) {
			update_post_meta( $post_id, '_vl_webinar_materials', $materials_json );
		}
	}
}
