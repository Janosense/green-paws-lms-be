<?php

declare(strict_types=1);

namespace VL\LMS\Admin\MetaBoxes;

use VL\LMS\Support\PlainText;
use WP_Post;

/**
 * Meta-box for `vl_lesson` posts.
 *
 * Surfaces a cascading parent picker — first a self-paced `vl_course`
 * select, then (only when a course is chosen) a `vl_module` select
 * scoped to that course's children — plus the existing content fields.
 * The selection is collapsed to a single `post_parent` write: module
 * wins, course is the fallback. Because `vl_lesson` is
 * `hierarchical: false`, WordPress does not render a Parent dropdown of
 * its own. {@see \VL\LMS\Learn\EntityHierarchy::resolve_lesson_course()}
 * walks both shapes (lesson → course, lesson → module → course).
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

		$this->render_section_heading( 'Батьківська одиниця' );

		$courses        = $this->query_self_paced_courses();
		$modules        = $this->query_modules_with_course();
		$current_parent = (int) $post->post_parent;
		$parent_type    = $current_parent > 0 ? (string) get_post_type( $current_parent ) : '';

		$selected_module_id = 'vl_module' === $parent_type ? $current_parent : 0;
		$selected_course_id = 0;
		if ( 'vl_course' === $parent_type ) {
			$selected_course_id = $current_parent;
		} elseif ( 'vl_module' === $parent_type
			&& isset( $modules[ (string) $selected_module_id ] ) ) {
			$selected_course_id = (int) $modules[ (string) $selected_module_id ]['course_id'];
		} elseif ( 0 === $current_parent ) {
			// Fresh lesson opened via a "Додати урок" button — the URL
			// carries ?vl_parent_id=<course|module id>. A module hint also
			// pins its parent course; a course hint stands alone.
			$hint = $this->parent_hint_from_query_string();
			if ( isset( $modules[ (string) $hint ] ) ) {
				$selected_module_id = $hint;
				$selected_course_id = (int) $modules[ (string) $hint ]['course_id'];
			} elseif ( $hint > 0 && isset( $courses[ (string) $hint ] ) ) {
				$selected_course_id = $hint;
			}
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
			'_vl_lesson_course_id',
			'Батьківський курс',
			(string) $selected_course_id,
			$course_options
		);

		$this->render_module_select_row( $modules, $selected_module_id, $selected_course_id );

		$this->render_section_heading( 'Параметри' );
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

		$course_id_raw = $this->post_int( '_vl_lesson_course_id', 0 );
		$module_id_raw = $this->post_int( '_vl_lesson_module_id', 0 );
		if ( null !== $course_id_raw || null !== $module_id_raw ) {
			$course_id = $course_id_raw ?? 0;
			$module_id = $module_id_raw ?? 0;

			if ( 0 !== $course_id && 'vl_course' !== get_post_type( $course_id ) ) {
				$course_id = 0;
			}

			$new_parent = $course_id;
			if ( $module_id > 0 && $course_id > 0 ) {
				$module_type   = (string) get_post_type( $module_id );
				$module_parent = (int) get_post_field( 'post_parent', $module_id );
				if ( 'vl_module' === $module_type && $module_parent === $course_id ) {
					$new_parent = $module_id;
				}
			}

			$current_parent = (int) get_post_field( 'post_parent', $post_id );
			if ( $new_parent !== $current_parent ) {
				wp_update_post(
					[
						'ID'          => $post_id,
						'post_parent' => $new_parent,
					]
				);
			}
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

	/**
	 * Raw `?vl_parent_id=` hint from a "Додати урок" button — a `vl_course`
	 * id (course-direct list) or a `vl_module` id (module list). The caller
	 * resolves which by looking the id up in its course / module maps.
	 * Returns 0 when absent or non-positive.
	 */
	private function parent_hint_from_query_string(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only hint, no state change.
		if ( ! isset( $_GET['vl_parent_id'] ) ) {
			return 0;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$candidate = (int) $_GET['vl_parent_id'];
		return $candidate > 0 ? $candidate : 0;
	}

	/**
	 * Render the module `<select>` with per-option `data-course` and an
	 * inline JS filter that scopes options to the chosen course and
	 * hides the row entirely when zero modules match.
	 *
	 * @param array<string, array{title: string, course_id: int}> $modules
	 */
	private function render_module_select_row(
		array $modules,
		int $selected_module_id,
		int $selected_course_id
	): void {
		$has_visible_modules = false;
		foreach ( $modules as $data ) {
			if ( $selected_course_id > 0 && (int) $data['course_id'] === $selected_course_id ) {
				$has_visible_modules = true;
				break;
			}
		}
		$display = ( $selected_course_id > 0 && $has_visible_modules ) ? '' : 'display:none';

		printf(
			'<div class="vl-lms-row" id="vl-lms-lesson-module-row" style="%s">'
			. '<label for="_vl_lesson_module_id">%s</label>'
			. '<select id="_vl_lesson_module_id" name="_vl_lesson_module_id">',
			esc_attr( $display ),
			esc_html( 'Батьківський модуль' )
		);
		printf(
			'<option value="0"%s>%s</option>',
			selected( $selected_module_id, 0, false ),
			esc_html( '— Без модуля —' )
		);
		foreach ( $modules as $module_id => $data ) {
			printf(
				'<option value="%1$s" data-course="%2$d"%3$s>%4$s</option>',
				esc_attr( (string) $module_id ),
				(int) $data['course_id'],
				selected( $selected_module_id, (int) $module_id, false ),
				esc_html( (string) $data['title'] )
			);
		}
		echo '</select></div>';

		echo "<script>\n"
			. "(function(){\n"
			. "  var courseSelect = document.getElementById('_vl_lesson_course_id');\n"
			. "  var moduleSelect = document.getElementById('_vl_lesson_module_id');\n"
			. "  var moduleRow    = document.getElementById('vl-lms-lesson-module-row');\n"
			. "  if (!courseSelect || !moduleSelect || !moduleRow) { return; }\n"
			. "  function sync(){\n"
			. "    var courseId = courseSelect.value;\n"
			. "    var visible  = 0;\n"
			. "    Array.prototype.forEach.call(moduleSelect.options, function(opt){\n"
			. "      if (opt.value === '0') { return; }\n"
			. "      var match = String(opt.getAttribute('data-course')) === String(courseId);\n"
			. "      opt.hidden = !match;\n"
			. "      if (!match && opt.selected) { moduleSelect.value = '0'; }\n"
			. "      if (match) { visible++; }\n"
			. "    });\n"
			. "    moduleRow.style.display = (courseId !== '0' && visible > 0) ? '' : 'none';\n"
			. "  }\n"
			. "  courseSelect.addEventListener('change', sync);\n"
			. "  sync();\n"
			. "})();\n"
			. "</script>\n";
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
	 * Return `vl_module` posts paired with their parent course id, so the
	 * client-side filter script can scope the module dropdown to the
	 * currently-selected course. `course_id = 0` means the module is
	 * unattached and will never be visible in the cascade.
	 *
	 * @return array<string, array{title: string, course_id: int}>
	 */
	private function query_modules_with_course(): array {
		$posts = get_posts(
			[
				'post_type'      => 'vl_module',
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
		foreach ( $posts as $module ) {
			if ( ! $module instanceof WP_Post ) {
				continue;
			}
			$title                       = '' !== $module->post_title
				? $module->post_title
				: sprintf( '#%d', (int) $module->ID );
			$out[ (string) $module->ID ] = [
				'title'     => $title,
				'course_id' => (int) $module->post_parent,
			];
		}
		return $out;
	}
}
