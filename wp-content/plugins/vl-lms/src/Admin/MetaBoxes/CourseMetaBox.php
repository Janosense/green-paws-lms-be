<?php

declare(strict_types=1);

namespace VL\LMS\Admin\MetaBoxes;

use WP_Post;

/**
 * Course meta-box — typed editor for `vl_course` post-meta keys.
 *
 * Replaces the default WordPress "Custom Fields" key/value table with a
 * sectioned form covering general info, pricing, cohort scheduling, and
 * completion. Field-level sanitization mirrors the
 * {@see \VL\LMS\CPT\CourseType::meta_fields()} `register_post_meta`
 * sanitize callbacks; the duplicate is intentional defence-in-depth so
 * the meta-box save is correct independent of REST-side guards.
 *
 * @author Tymofii Synianskyi
 */
class CourseMetaBox extends AbstractMetaBox {

	private const string TYPE_SELF_PACED = 'self_paced';
	private const string TYPE_COHORT     = 'cohort';

	/** @var list<string> */
	private const array COURSE_TYPES = [ self::TYPE_SELF_PACED, self::TYPE_COHORT ];

	public function __construct() {
		parent::__construct( 'vl_course' );
	}

	public function id(): string {
		return 'vl_lms_course';
	}

	public function title(): string {
		return 'Параметри курсу';
	}

	public function render( WP_Post $post ): void {
		$this->render_nonce();

		$cover_id    = $this->meta_int( $post->ID, '_vl_course_cover_image_id' );
		$cover_thumb = '';
		if ( $cover_id > 0 ) {
			$thumb = wp_get_attachment_image( $cover_id, 'thumbnail', false, [ 'class' => 'vl-lms-cover-thumb' ] );
			if ( is_string( $thumb ) ) {
				$cover_thumb = $thumb;
			}
		}

		echo '<div class="vl-lms-meta-box">';

		$this->render_section_heading( 'Загальне' );
		$this->render_select_row(
			'_vl_course_type',
			'Тип курсу',
			$this->meta_string( $post->ID, '_vl_course_type' ),
			[
				self::TYPE_SELF_PACED => 'Самостійний',
				self::TYPE_COHORT     => 'Когортний',
			]
		);
		$this->render_text_row(
			'_vl_course_duration_hours',
			'Тривалість (годин)',
			(string) $this->meta_float( $post->ID, '_vl_course_duration_hours' ),
			'number',
			'min="0" step="0.5"'
		);
		$this->render_text_row(
			'_vl_course_preview_video_url',
			'Промо-відео (URL)',
			$this->meta_string( $post->ID, '_vl_course_preview_video_url' ),
			'url'
		);

		// Cover image picker.
		echo '<div class="vl-lms-row vl-lms-row--media"><label for="_vl_course_cover_image_id">Обкладинка</label>';
		printf(
			'<div class="vl-lms-media-control" data-target="_vl_course_cover_image_id">'
			. '<span class="vl-lms-media-thumb">%1$s</span>'
			. '<input type="hidden" id="_vl_course_cover_image_id" name="_vl_course_cover_image_id" value="%2$d" />'
			. '<button type="button" class="button vl-lms-media-pick">Обрати</button>'
			. '<button type="button" class="button-link vl-lms-media-clear">Очистити</button>'
			. '</div>',
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_get_attachment_image returns escaped HTML.
			$cover_thumb,
			(int) $cover_id
		);
		echo '</div>';

		$this->render_section_heading( 'Ціна та доступ' );
		$this->render_text_row(
			'_vl_course_price',
			'Ціна',
			(string) $this->meta_float( $post->ID, '_vl_course_price' ),
			'number',
			'min="0" step="0.01"'
		);
		$this->render_text_row(
			'_vl_course_currency',
			'Валюта',
			$this->meta_string( $post->ID, '_vl_course_currency' ),
			'text',
			'maxlength="3"'
		);
		$this->render_checkbox_row(
			'_vl_course_enrollment_open',
			'Реєстрація відкрита',
			'' !== $this->meta_string( $post->ID, '_vl_course_enrollment_open' )
				&& '0' !== $this->meta_string( $post->ID, '_vl_course_enrollment_open' )
		);
		$this->render_text_row(
			'_vl_course_max_students',
			'Макс. студентів (0 = необмежено)',
			(string) $this->meta_int( $post->ID, '_vl_course_max_students' ),
			'number',
			'min="0"'
		);

		$this->render_section_heading( 'Когортні налаштування' );
		$this->render_text_row(
			'_vl_course_enrollment_opens_at',
			'Реєстрація відкривається',
			$this->meta_string( $post->ID, '_vl_course_enrollment_opens_at' ),
			'datetime-local'
		);
		$this->render_text_row(
			'_vl_course_enrollment_closes_at',
			'Реєстрація закривається',
			$this->meta_string( $post->ID, '_vl_course_enrollment_closes_at' ),
			'datetime-local'
		);
		$this->render_text_row(
			'_vl_course_starts_at',
			'Початок',
			$this->meta_string( $post->ID, '_vl_course_starts_at' ),
			'datetime-local'
		);
		$this->render_text_row(
			'_vl_course_ends_at',
			'Завершення',
			$this->meta_string( $post->ID, '_vl_course_ends_at' ),
			'datetime-local'
		);

		$this->render_section_heading( 'Завершення' );
		$this->render_text_row(
			'_vl_course_passing_threshold',
			'Поріг проходження (%)',
			(string) $this->meta_int( $post->ID, '_vl_course_passing_threshold' ),
			'number',
			'min="0" max="100"'
		);
		$this->render_checkbox_row(
			'_vl_course_certificate_enabled',
			'Видавати сертифікат при завершенні',
			$this->meta_bool( $post->ID, '_vl_course_certificate_enabled' )
		);

		echo '</div>';
	}

	public function save( int $post_id, WP_Post $post ): void {
		unset( $post );

		if ( ! $this->verified( $post_id ) ) {
			return;
		}

		$type = $this->post_enum( '_vl_course_type', self::COURSE_TYPES );
		if ( null !== $type ) {
			update_post_meta( $post_id, '_vl_course_type', $type );
		}

		$duration = $this->post_float( '_vl_course_duration_hours', 0.0 );
		if ( null !== $duration ) {
			update_post_meta( $post_id, '_vl_course_duration_hours', $duration );
		}

		$preview = $this->post_raw( '_vl_course_preview_video_url' );
		if ( null !== $preview ) {
			update_post_meta( $post_id, '_vl_course_preview_video_url', esc_url_raw( $preview ) );
		}

		$cover = $this->post_int( '_vl_course_cover_image_id', 0 );
		if ( null !== $cover ) {
			update_post_meta( $post_id, '_vl_course_cover_image_id', $cover );
		}

		$price = $this->post_float( '_vl_course_price', 0.0 );
		if ( null !== $price ) {
			update_post_meta( $post_id, '_vl_course_price', $price );
		}

		$currency = $this->post_string( '_vl_course_currency' );
		if ( null !== $currency ) {
			$currency = strtoupper( substr( $currency, 0, 3 ) );
			update_post_meta( $post_id, '_vl_course_currency', $currency );
		}

		update_post_meta(
			$post_id,
			'_vl_course_enrollment_open',
			$this->post_checkbox( '_vl_course_enrollment_open' )
		);

		$max_students = $this->post_int( '_vl_course_max_students', 0 );
		if ( null !== $max_students ) {
			update_post_meta( $post_id, '_vl_course_max_students', $max_students );
		}

		$datetime_keys = [
			'_vl_course_enrollment_opens_at',
			'_vl_course_enrollment_closes_at',
			'_vl_course_starts_at',
			'_vl_course_ends_at',
		];
		foreach ( $datetime_keys as $key ) {
			$value = $this->post_string( $key );
			if ( null !== $value && '' !== $value ) {
				update_post_meta( $post_id, $key, $value );
			} elseif ( null !== $value ) {
				update_post_meta( $post_id, $key, '' );
			}
		}

		$threshold = $this->post_int( '_vl_course_passing_threshold', 0, 100 );
		if ( null !== $threshold ) {
			update_post_meta( $post_id, '_vl_course_passing_threshold', $threshold );
		}

		update_post_meta(
			$post_id,
			'_vl_course_certificate_enabled',
			$this->post_checkbox( '_vl_course_certificate_enabled' )
		);
	}
}
