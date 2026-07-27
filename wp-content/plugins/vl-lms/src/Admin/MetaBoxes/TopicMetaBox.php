<?php

declare(strict_types=1);

namespace VL\LMS\Admin\MetaBoxes;

use VL\LMS\Support\PlainText;
use WP_Post;

/**
 * Meta-box for `vl_topic` posts.
 *
 * Surfaces a cascading parent picker — first a self-paced `vl_course`
 * select, then a `vl_lesson` select scoped to that course's lessons
 * (direct lesson → course or lesson → module → course) — plus the
 * existing video metadata fields. The selection is collapsed to a single
 * `post_parent = lesson_id` write. Topics can only belong to lessons.
 *
 * For brand-new topics opened via the "Додати тему" button on a lesson
 * screen (URL contains `vl_parent_id=<lesson_id>`), the picker is
 * pre-selected to that lesson and its root course so the editor can
 * publish without touching the dropdowns.
 *
 * Because `vl_topic` is `hierarchical: false`, WordPress does not render
 * a Parent dropdown of its own — the selection is written back via
 * `wp_update_post()`.
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

		$this->render_section_heading( 'Батьківський урок' );

		$courses        = $this->query_self_paced_courses();
		$lessons        = $this->query_lessons_with_course();
		$current_parent = (int) $post->post_parent;

		$selected_lesson_id = 'vl_lesson' === ( $current_parent > 0 ? (string) get_post_type( $current_parent ) : '' )
			? $current_parent
			: 0;

		if ( 0 === $selected_lesson_id ) {
			$selected_lesson_id = $this->lesson_hint_from_query_string( $lessons );
		}

		$selected_course_id = 0;
		if ( $selected_lesson_id > 0 && isset( $lessons[ (string) $selected_lesson_id ] ) ) {
			$selected_course_id = (int) $lessons[ (string) $selected_lesson_id ]['course_id'];
		}

		if ( $selected_course_id > 0 && ! isset( $courses[ (string) $selected_course_id ] ) ) {
			$current_course_title = PlainText::from_html( (string) get_the_title( $selected_course_id ) );
			if ( '' !== $current_course_title ) {
				$courses[ (string) $selected_course_id ] = $current_course_title . ' (когортний)';
			}
		}
		$course_options = [ '0' => '— Без курсу —' ];
		foreach ( $courses as $course_id => $course_title ) {
			$course_options[ (string) $course_id ] = $course_title;
		}
		$this->render_select_row(
			'_vl_topic_course_id',
			'Батьківський курс',
			(string) $selected_course_id,
			$course_options
		);

		$this->render_lesson_select_row( $lessons, $selected_lesson_id, $selected_course_id );

		$this->render_section_heading( 'Параметри' );
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

		$lesson_id_raw = $this->post_int( '_vl_topic_lesson_id', 0 );
		if ( null !== $lesson_id_raw ) {
			$lesson_id = $lesson_id_raw;
			if ( $lesson_id > 0 && 'vl_lesson' !== get_post_type( $lesson_id ) ) {
				$lesson_id = 0;
			}
			$current_parent = (int) get_post_field( 'post_parent', $post_id );
			if ( $lesson_id !== $current_parent ) {
				wp_update_post(
					[
						'ID'          => $post_id,
						'post_parent' => $lesson_id,
					]
				);
			}
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

	/**
	 * Render the lesson `<select>` with per-option `data-course` and an
	 * inline JS filter that scopes options to the chosen course and
	 * hides the row entirely when zero lessons match.
	 *
	 * @param array<string, array{title: string, course_id: int}> $lessons
	 */
	private function render_lesson_select_row(
		array $lessons,
		int $selected_lesson_id,
		int $selected_course_id
	): void {
		$has_visible_lessons = false;
		foreach ( $lessons as $data ) {
			if ( $selected_course_id > 0 && (int) $data['course_id'] === $selected_course_id ) {
				$has_visible_lessons = true;
				break;
			}
		}
		$display = ( $selected_course_id > 0 && $has_visible_lessons ) ? '' : 'display:none';

		printf(
			'<div class="vl-lms-row" id="vl-lms-topic-lesson-row" style="%s">'
			. '<label for="_vl_topic_lesson_id">%s</label>'
			. '<select id="_vl_topic_lesson_id" name="_vl_topic_lesson_id">',
			esc_attr( $display ),
			esc_html( 'Батьківський урок' )
		);
		printf(
			'<option value="0"%s>%s</option>',
			selected( $selected_lesson_id, 0, false ),
			esc_html( '— Без уроку —' )
		);
		foreach ( $lessons as $lesson_id => $data ) {
			printf(
				'<option value="%1$s" data-course="%2$d"%3$s>%4$s</option>',
				esc_attr( (string) $lesson_id ),
				(int) $data['course_id'],
				selected( $selected_lesson_id, (int) $lesson_id, false ),
				esc_html( (string) $data['title'] )
			);
		}
		echo '</select></div>';

		echo "<script>\n"
			. "(function(){\n"
			. "  var courseSelect = document.getElementById('_vl_topic_course_id');\n"
			. "  var lessonSelect = document.getElementById('_vl_topic_lesson_id');\n"
			. "  var lessonRow    = document.getElementById('vl-lms-topic-lesson-row');\n"
			. "  if (!courseSelect || !lessonSelect || !lessonRow) { return; }\n"
			. "  function sync(){\n"
			. "    var courseId = courseSelect.value;\n"
			. "    var visible  = 0;\n"
			. "    Array.prototype.forEach.call(lessonSelect.options, function(opt){\n"
			. "      if (opt.value === '0') { return; }\n"
			. "      var match = String(opt.getAttribute('data-course')) === String(courseId);\n"
			. "      opt.hidden = !match;\n"
			. "      if (!match && opt.selected) { lessonSelect.value = '0'; }\n"
			. "      if (match) { visible++; }\n"
			. "    });\n"
			. "    lessonRow.style.display = (courseId !== '0' && visible > 0) ? '' : 'none';\n"
			. "  }\n"
			. "  courseSelect.addEventListener('change', sync);\n"
			. "  sync();\n"
			. "})();\n"
			. "</script>\n";
	}

	/**
	 * If the editor arrived via the "Додати тему" button on a lesson, the
	 * URL carries `?vl_parent_id=<lesson_id>`. Return that id when it
	 * resolves to a known lesson in our list — otherwise 0.
	 *
	 * @param array<string, array{title: string, course_id: int}> $lessons
	 */
	private function lesson_hint_from_query_string( array $lessons ): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only hint, no state change.
		if ( ! isset( $_GET['vl_parent_id'] ) ) {
			return 0;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$candidate = (int) $_GET['vl_parent_id'];
		if ( $candidate <= 0 ) {
			return 0;
		}
		return isset( $lessons[ (string) $candidate ] ) ? $candidate : 0;
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

	/**
	 * Return `vl_lesson` posts paired with their root course id (walking
	 * lesson → module → course when necessary). `course_id = 0` means the
	 * lesson is orphaned and won't appear under any course in the cascade.
	 *
	 * @return array<string, array{title: string, course_id: int}>
	 */
	private function query_lessons_with_course(): array {
		$posts = get_posts(
			[
				'post_type'      => 'vl_lesson',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			]
		);

		$out = [];
		if ( ! is_array( $posts ) ) {
			return $out;
		}
		foreach ( $posts as $lesson ) {
			if ( ! $lesson instanceof WP_Post ) {
				continue;
			}
			$title                       = '' !== $lesson->post_title
				? $lesson->post_title
				: sprintf( '#%d', (int) $lesson->ID );
			$out[ (string) $lesson->ID ] = [
				'title'     => $title,
				'course_id' => $this->resolve_lesson_root_course( (int) $lesson->post_parent ),
			];
		}
		return $out;
	}

	/**
	 * Walk a lesson's parent chain to find its root `vl_course` id, or 0
	 * if the lesson is unattached. Mirrors the runtime resolver in
	 * {@see \VL\LMS\Learn\EntityHierarchy::resolve_lesson_course()} but
	 * accepts any post status (admin context).
	 */
	private function resolve_lesson_root_course( int $parent_id ): int {
		if ( $parent_id <= 0 ) {
			return 0;
		}
		$parent_type = (string) get_post_type( $parent_id );
		if ( 'vl_course' === $parent_type ) {
			return $parent_id;
		}
		if ( 'vl_module' === $parent_type ) {
			$grandparent = (int) get_post_field( 'post_parent', $parent_id );
			if ( $grandparent > 0 && 'vl_course' === get_post_type( $grandparent ) ) {
				return $grandparent;
			}
		}
		return 0;
	}
}
