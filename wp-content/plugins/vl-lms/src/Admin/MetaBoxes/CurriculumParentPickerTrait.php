<?php

declare(strict_types=1);

namespace VL\LMS\Admin\MetaBoxes;

use WP_Post;

/**
 * Cascading parent picker shared by the flexible-parent CPTs `vl_quiz` and
 * `vl_assignment`.
 *
 * Both attach under a `vl_course`, `vl_module`, `vl_lesson`, or `vl_session`
 * via `post_parent`, but — like the lesson / topic boxes — neither is
 * `hierarchical`, so WordPress renders no Parent dropdown of its own.
 *
 * The picker collapses the four-way choice into two selects:
 *  1. a **course** select listing every course (self-paced *and* cohort);
 *  2. a **section** select scoped to the chosen course, offering
 *     "— Весь курс —" (attach to the course itself) plus optgroups of the
 *     course's modules, lessons, and sessions.
 *
 * The selection collapses to a single `post_parent` write: the chosen
 * section wins, the course is the fallback, `0` (no course) detaches. The
 * section select carries `data-course` on every option so a small inline
 * script can scope it to the selected course and hide now-empty optgroups.
 *
 * Consumers supply a field prefix (e.g. `_vl_quiz_parent`) — the form field
 * names double as POST keys but are **never** meta keys — and a DOM-safe
 * key (e.g. `quiz`) for unique element ids.
 *
 * @author Tymofii Synianskyi
 */
trait CurriculumParentPickerTrait {

	/** @var list<string> */
	private static array $curriculum_unit_types = [ 'vl_module', 'vl_lesson', 'vl_session' ];

	/**
	 * Render the cascading course → section picker for `$post`.
	 *
	 * @param string $prefix  Field/POST key prefix, e.g. `_vl_quiz_parent`.
	 * @param string $dom_key DOM-safe slug for element ids, e.g. `quiz`.
	 */
	protected function render_curriculum_parent_picker( WP_Post $post, string $prefix, string $dom_key ): void {
		$this->render_section_heading( 'Батьківська одиниця' );

		$courses = $this->query_all_courses();
		$units   = $this->query_curriculum_units();

		$current_parent = (int) $post->post_parent;
		$parent_type    = $current_parent > 0 ? (string) get_post_type( $current_parent ) : '';

		$selected_unit_id   = 0;
		$selected_course_id = 0;

		if ( 'vl_course' === $parent_type ) {
			$selected_course_id = $current_parent;
		} elseif ( in_array( $parent_type, self::$curriculum_unit_types, true )
			&& isset( $units[ (string) $current_parent ] ) ) {
			$selected_unit_id   = $current_parent;
			$selected_course_id = (int) $units[ (string) $current_parent ]['course_id'];
		} elseif ( 0 === $current_parent ) {
			// Fresh post opened via a "Додати тест/завдання" button — the URL
			// carries ?vl_parent_id=<course|module|lesson|session id>.
			$hint = $this->parent_hint_from_query_string();
			if ( isset( $units[ (string) $hint ] ) ) {
				$selected_unit_id   = $hint;
				$selected_course_id = (int) $units[ (string) $hint ]['course_id'];
			} elseif ( $hint > 0 && isset( $courses[ (string) $hint ] ) ) {
				$selected_course_id = $hint;
			}
		}

		// A course attached but missing from the list (e.g. trashed) still
		// needs a label so the editor sees the current attachment.
		if ( $selected_course_id > 0 && ! isset( $courses[ (string) $selected_course_id ] ) ) {
			$title = (string) get_the_title( $selected_course_id );
			if ( '' !== $title ) {
				$courses[ (string) $selected_course_id ] = $title;
			}
		}

		$course_options = [ '0' => '— Без курсу —' ];
		foreach ( $courses as $course_id => $course_title ) {
			$course_options[ (string) $course_id ] = $course_title;
		}
		$this->render_select_row(
			$prefix . '_course_id',
			'Батьківський курс',
			(string) $selected_course_id,
			$course_options
		);

		$this->render_unit_select_row( $prefix, $dom_key, $units, $selected_unit_id, $selected_course_id );
	}

	/**
	 * Resolve and persist `post_parent` from the picker's two POST fields.
	 * No-op when the picker was not submitted (fields absent), mirroring the
	 * lesson / topic boxes so unrelated saves never touch `post_parent`.
	 */
	protected function save_curriculum_parent( int $post_id, string $prefix ): void {
		$course_id_raw = $this->post_int( $prefix . '_course_id', 0 );
		$unit_id_raw   = $this->post_int( $prefix . '_unit_id', 0 );
		if ( null === $course_id_raw && null === $unit_id_raw ) {
			return;
		}

		$course_id = $course_id_raw ?? 0;
		$unit_id   = $unit_id_raw ?? 0;

		if ( 0 !== $course_id && 'vl_course' !== get_post_type( $course_id ) ) {
			$course_id = 0;
		}

		$new_parent = $course_id;
		if ( $unit_id > 0 && $course_id > 0 ) {
			$unit_type = (string) get_post_type( $unit_id );
			if ( in_array( $unit_type, self::$curriculum_unit_types, true )
				&& $this->curriculum_unit_root_course( $unit_id, $unit_type ) === $course_id ) {
				$new_parent = $unit_id;
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

	/**
	 * Render the section `<select>` (course-scoped) with grouped options and
	 * the inline filter script. Options carry `data-course`; the script
	 * hides non-matching options, collapses emptied optgroups, and shows the
	 * row only once a course is chosen.
	 *
	 * @param array<string, array{title: string, type: string, course_id: int}> $units
	 */
	private function render_unit_select_row(
		string $prefix,
		string $dom_key,
		array $units,
		int $selected_unit_id,
		int $selected_course_id
	): void {
		$display = $selected_course_id > 0 ? '' : 'display:none';
		$field   = $prefix . '_unit_id';
		$row_id  = 'vl-lms-' . $dom_key . '-unit-row';

		printf(
			'<div class="vl-lms-row" id="%1$s" style="%2$s">'
			. '<label for="%3$s">%4$s</label>'
			. '<select id="%3$s" name="%3$s">',
			esc_attr( $row_id ),
			esc_attr( $display ),
			esc_attr( $field ),
			esc_html( 'Розділ курсу' )
		);
		printf(
			'<option value="0"%s>%s</option>',
			selected( $selected_unit_id, 0, false ),
			esc_html( '— Весь курс —' )
		);

		$group_labels = [
			'vl_module'  => 'Модулі',
			'vl_lesson'  => 'Уроки',
			'vl_session' => 'Сесії',
		];
		foreach ( $group_labels as $type => $group_label ) {
			$group_units = array_filter(
				$units,
				static fn ( array $data ): bool => $data['type'] === $type
			);
			if ( [] === $group_units ) {
				continue;
			}
			printf( '<optgroup label="%s">', esc_attr( $group_label ) );
			foreach ( $group_units as $unit_id => $data ) {
				printf(
					'<option value="%1$s" data-course="%2$d"%3$s>%4$s</option>',
					esc_attr( (string) $unit_id ),
					(int) $data['course_id'],
					selected( $selected_unit_id, (int) $unit_id, false ),
					esc_html( (string) $data['title'] )
				);
			}
			echo '</optgroup>';
		}
		echo '</select></div>';

		printf(
			"<script>\n"
			. "(function(){\n"
			. "  var courseSelect = document.getElementById('%1\$s_course_id');\n"
			. "  var unitSelect   = document.getElementById('%2\$s');\n"
			. "  var unitRow      = document.getElementById('%3\$s');\n"
			. "  if (!courseSelect || !unitSelect || !unitRow) { return; }\n"
			. "  function sync(){\n"
			. "    var courseId = courseSelect.value;\n"
			. "    Array.prototype.forEach.call(unitSelect.querySelectorAll('option'), function(opt){\n"
			. "      if (opt.value === '0') { return; }\n"
			. "      var match = String(opt.getAttribute('data-course')) === String(courseId);\n"
			. "      opt.hidden = !match;\n"
			. "      if (!match && opt.selected) { unitSelect.value = '0'; }\n"
			. "    });\n"
			. "    Array.prototype.forEach.call(unitSelect.querySelectorAll('optgroup'), function(grp){\n"
			. "      var any = false;\n"
			. "      Array.prototype.forEach.call(grp.querySelectorAll('option'), function(opt){ if (!opt.hidden) { any = true; } });\n"
			. "      grp.hidden = !any;\n"
			. "    });\n"
			. "    unitRow.style.display = (courseId !== '0') ? '' : 'none';\n"
			. "  }\n"
			. "  courseSelect.addEventListener('change', sync);\n"
			. "  sync();\n"
			. "})();\n"
			. "</script>\n",
			esc_js( $prefix ),
			esc_js( $field ),
			esc_js( $row_id )
		);
	}

	/**
	 * `?vl_parent_id=` hint from a reverse-list "Add new" button. Returns the
	 * raw positive id (a course / module / lesson / session) or 0.
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
	 * Every course keyed by id → title, ordered by title. Both self-paced
	 * and cohort courses are listed: a quiz/assignment may attach to a
	 * self-paced course's module/lesson or to a cohort course's session.
	 *
	 * @return array<string, string>
	 */
	private function query_all_courses(): array {
		$posts = get_posts(
			[
				'post_type'      => 'vl_course',
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
		foreach ( $posts as $course ) {
			if ( ! $course instanceof WP_Post ) {
				continue;
			}
			$out[ (string) $course->ID ] = '' !== $course->post_title
				? $course->post_title
				: sprintf( '#%d', (int) $course->ID );
		}
		return $out;
	}

	/**
	 * Every module, lesson, and session keyed by id, paired with its type and
	 * resolved root course id (lessons walk lesson → module → course). A
	 * `course_id` of 0 means the unit is orphaned and never appears in the
	 * cascade.
	 *
	 * @return array<string, array{title: string, type: string, course_id: int}>
	 */
	private function query_curriculum_units(): array {
		$posts = get_posts(
			[
				'post_type'      => self::$curriculum_unit_types,
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
		foreach ( $posts as $unit ) {
			if ( ! $unit instanceof WP_Post ) {
				continue;
			}
			$out[ (string) $unit->ID ] = [
				'title'     => '' !== $unit->post_title ? $unit->post_title : sprintf( '#%d', (int) $unit->ID ),
				'type'      => (string) $unit->post_type,
				'course_id' => $this->curriculum_unit_root_course( (int) $unit->ID, (string) $unit->post_type ),
			];
		}
		return $out;
	}

	/**
	 * Resolve a unit's root `vl_course` id, or 0 when it is unattached.
	 * Modules and sessions hang directly off the course; lessons may sit one
	 * module deep. Mirrors the runtime resolvers but accepts any post status
	 * (admin context).
	 */
	private function curriculum_unit_root_course( int $unit_id, string $unit_type ): int {
		$parent = (int) get_post_field( 'post_parent', $unit_id );
		if ( $parent <= 0 ) {
			return 0;
		}
		$parent_type = (string) get_post_type( $parent );
		if ( 'vl_course' === $parent_type ) {
			return $parent;
		}
		if ( 'vl_lesson' === $unit_type && 'vl_module' === $parent_type ) {
			$grandparent = (int) get_post_field( 'post_parent', $parent );
			if ( $grandparent > 0 && 'vl_course' === get_post_type( $grandparent ) ) {
				return $grandparent;
			}
		}
		return 0;
	}
}
