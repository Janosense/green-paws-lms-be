<?php

declare(strict_types=1);

namespace VL\LMS\Admin\MetaBoxes;

use VL\LMS\Support\PlainText;
use WP_Post;

/**
 * Meta-box for `vl_session` posts.
 *
 * Editable fields cover the parent course (`post_parent`), session
 * schedule, status, recording window, and instructor-uploaded materials.
 * Because `vl_session` is `hierarchical: false`, WordPress does not
 * render a Parent dropdown of its own — this meta-box surfaces a course
 * picker (cohort-type `vl_course` posts) and writes the selection back
 * via `wp_update_post()`. Zoom-managed fields (`_vl_session_zoom_*`,
 * `_vl_session_recording_url`) are rendered as read-only displays so
 * authors see the synced state without being able to overwrite values
 * produced by {@see \VL\LMS\Services\Zoom\Sync\MeetingSynchronizer} or
 * the recording webhook handler.
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

		$this->render_section_heading( 'Курс' );
		$courses   = $this->query_cohort_courses();
		$parent_id = (int) $post->post_parent;
		if ( $parent_id > 0 && ! isset( $courses[ (string) $parent_id ] ) ) {
			$current_title = PlainText::from_html( (string) get_the_title( $parent_id ) );
			if ( '' !== $current_title ) {
				$courses[ (string) $parent_id ] = $current_title . ' (не когортний)';
			}
		}
		$options = [ '0' => '— Без курсу —' ];
		foreach ( $courses as $course_id => $course_title ) {
			$options[ (string) $course_id ] = $course_title;
		}
		$this->render_select_row(
			'_vl_session_course_id',
			'Батьківський курс',
			(string) $parent_id,
			$options
		);

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
			$this->iso8601_to_datetime_local( $this->meta_string( $post->ID, '_vl_session_scheduled_start' ) ),
			'datetime-local'
		);
		$this->render_text_row(
			'_vl_session_scheduled_end',
			'Запланований кінець',
			$this->iso8601_to_datetime_local( $this->meta_string( $post->ID, '_vl_session_scheduled_end' ) ),
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
			$this->iso8601_to_datetime_local( $this->meta_string( $post->ID, '_vl_session_recording_available_until' ) ),
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

		$course_id = $this->post_int( '_vl_session_course_id', 0 );
		if ( null !== $course_id ) {
			$current_parent = (int) get_post_field( 'post_parent', $post_id );
			if ( $course_id !== $current_parent ) {
				$valid = ( 0 === $course_id ) || ( 'vl_course' === get_post_type( $course_id ) );
				if ( $valid ) {
					wp_update_post(
						[
							'ID'          => $post_id,
							'post_parent' => $course_id,
						]
					);
				}
			}
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
			if ( null === $value ) {
				continue;
			}
			update_post_meta( $post_id, $key, $this->datetime_local_to_iso8601( $value ) );
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

	/**
	 * Return cohort-type `vl_course` posts as `[ post_id => title ]`,
	 * ordered by title. The cohort filter mirrors the runtime rule
	 * enforced by services and REST controllers — sessions can only
	 * be meaningfully scheduled inside a cohort course.
	 *
	 * @return array<string, string>
	 */
	private function query_cohort_courses(): array {
		$posts = get_posts(
			[
				'post_type'      => 'vl_course',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'meta_query'     => [
					[
						'key'   => '_vl_course_type',
						'value' => 'cohort',
					],
				],
			]
		);

		$out = [];
		if ( ! is_array( $posts ) ) {
			return $out;
		}
		foreach ( $posts as $course ) {
			if ( ! $course instanceof WP_Post ) {
				continue;
			}
			$title                       = '' !== $course->post_title
				? $course->post_title
				: sprintf( '#%d', (int) $course->ID );
			$out[ (string) $course->ID ] = $title;
		}
		return $out;
	}
}
