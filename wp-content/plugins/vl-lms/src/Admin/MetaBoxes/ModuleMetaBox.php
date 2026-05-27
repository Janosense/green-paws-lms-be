<?php

declare(strict_types=1);

namespace VL\LMS\Admin\MetaBoxes;

use WP_Post;

/**
 * Meta-box for `vl_module` posts.
 *
 * Surfaces a parent-course picker (self-paced `vl_course` posts) and
 * three content fields: optional intro-video URL, total duration, and
 * a passing-threshold percentage that the runtime uses when surfacing
 * module-level completion. Because `vl_module` is `hierarchical: false`,
 * WordPress does not render a Parent dropdown of its own — the course
 * selection is written back via `wp_update_post()` (mirroring
 * {@see SessionMetaBox}).
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

		$this->render_section_heading( 'Курс' );
		$courses   = $this->query_self_paced_courses();
		$parent_id = (int) $post->post_parent;
		if ( 0 === $parent_id ) {
			$parent_id = $this->course_hint_from_query_string( $courses );
		}
		if ( $parent_id > 0 && ! isset( $courses[ (string) $parent_id ] ) ) {
			$current_title = (string) get_the_title( $parent_id );
			if ( '' !== $current_title ) {
				$courses[ (string) $parent_id ] = $current_title . ' (когортний)';
			}
		}
		$options = [ '0' => '— Без курсу —' ];
		foreach ( $courses as $course_id => $course_title ) {
			$options[ (string) $course_id ] = $course_title;
		}
		$this->render_select_row(
			'_vl_module_course_id',
			'Батьківський курс',
			(string) $parent_id,
			$options
		);

		$this->render_section_heading( 'Параметри' );
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

		$course_id = $this->post_int( '_vl_module_course_id', 0 );
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

	/**
	 * If the editor arrived via the "Додати модуль" button on a course,
	 * the URL carries `?vl_parent_id=<course_id>`. Return that id when it
	 * resolves to a known self-paced course — otherwise 0.
	 *
	 * @param array<string, string> $courses
	 */
	private function course_hint_from_query_string( array $courses ): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only hint, no state change.
		if ( ! isset( $_GET['vl_parent_id'] ) ) {
			return 0;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$candidate = (int) $_GET['vl_parent_id'];
		if ( $candidate <= 0 ) {
			return 0;
		}
		return isset( $courses[ (string) $candidate ] ) ? $candidate : 0;
	}

	/**
	 * Return self-paced `vl_course` posts as `[ post_id => title ]`,
	 * ordered by title. Courses lacking the `_vl_course_type` meta are
	 * treated as self-paced (see {@see \VL\LMS\Learn\CurriculumTransformer}),
	 * so the meta_query accepts either `'self_paced'` or NOT EXISTS.
	 *
	 * @return array<string, string>
	 */
	private function query_self_paced_courses(): array {
		$posts = get_posts(
			[
				'post_type'      => 'vl_course',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'meta_query'     => [
					'relation' => 'OR',
					[
						'key'   => '_vl_course_type',
						'value' => 'self_paced',
					],
					[
						'key'     => '_vl_course_type',
						'compare' => 'NOT EXISTS',
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
