<?php

declare(strict_types=1);

namespace VL\LMS\Admin\Columns;

use VL\LMS\Support\PlainText;
use WP_Query;

/**
 * Adds parent-context columns to the wp-admin list tables for the flat
 * course-bound CPTs `vl_module`, `vl_lesson`, `vl_topic`, and `vl_session`,
 * plus a course-type column on `vl_course` itself.
 *
 * The CPTs are flat (`hierarchical: false`); their relationships live in
 * `post_parent`. Without these columns an editor opening "Lessons" sees only
 * titles and dates and has no way to tell which course or module a lesson
 * belongs to without clicking through. The columns surface:
 *
 *  - `vl_course` : Type (`_vl_course_type`); also drops the noisy `vl_tag`
 *                  ("Tags") taxonomy column from the list table.
 *  - `vl_module` : Course, Lessons (count)
 *  - `vl_lesson` : Course, Module, Topics (count)
 *  - `vl_topic`  : Course (resolved via the parent lesson), Lesson
 *  - `vl_session`: Course, Date of delivery (`_vl_session_scheduled_start`)
 *
 * Counts are computed with a single `WP_Query` per row (`fields=ids`,
 * `posts_per_page=-1`) — the LMS list tables are paged at the WP default
 * (20) so the per-screen overhead is bounded.
 *
 * Also renders a "Course" parent filter above the `vl_module` list table
 * via `restrict_manage_posts`, and narrows the main query through
 * `parse_query` when the dropdown is set. The `vl_lesson` list table gets
 * the same "Course" filter plus a "Module" filter scoped to the chosen
 * course (options pre-rendered with `data-course`, narrowed by an inline
 * script — the `LessonMetaBox` cascade pattern).
 *
 * @author Tymofii Synianskyi
 */
class CurriculumListColumns {

	private const string COURSE_FILTER_PARAM = 'vl_course_id';

	private const string MODULE_FILTER_PARAM = 'vl_module_id';

	public function boot(): void {
		add_filter( 'manage_vl_course_posts_columns', [ $this, 'course_columns' ] );
		add_action( 'manage_vl_course_posts_custom_column', [ $this, 'render_course_column' ], 10, 2 );

		add_filter( 'manage_vl_module_posts_columns', [ $this, 'module_columns' ] );
		add_action( 'manage_vl_module_posts_custom_column', [ $this, 'render_module_column' ], 10, 2 );

		add_filter( 'manage_vl_lesson_posts_columns', [ $this, 'lesson_columns' ] );
		add_action( 'manage_vl_lesson_posts_custom_column', [ $this, 'render_lesson_column' ], 10, 2 );

		add_filter( 'manage_vl_topic_posts_columns', [ $this, 'topic_columns' ] );
		add_action( 'manage_vl_topic_posts_custom_column', [ $this, 'render_topic_column' ], 10, 2 );

		add_filter( 'manage_vl_session_posts_columns', [ $this, 'session_columns' ] );
		add_action( 'manage_vl_session_posts_custom_column', [ $this, 'render_session_column' ], 10, 2 );

		add_action( 'restrict_manage_posts', [ $this, 'render_module_course_filter' ] );
		add_action( 'parse_query', [ $this, 'apply_module_course_filter' ] );

		add_action( 'restrict_manage_posts', [ $this, 'render_lesson_filters' ] );
		add_action( 'parse_query', [ $this, 'apply_lesson_filters' ] );
	}

	/**
	 * Add a "Type" column to the `vl_course` list table and drop the
	 * `vl_tag` ("Tags") taxonomy column (registered with
	 * `show_admin_column`), which is noise on the course overview.
	 *
	 * @param array<string, string> $columns
	 * @return array<string, string>
	 */
	public function course_columns( array $columns ): array {
		unset( $columns['taxonomy-vl_tag'] );

		return self::insert_before(
			$columns,
			'date',
			[ 'vl_course_type' => 'Тип курсу' ]
		);
	}

	/**
	 * @param array<string, string> $columns
	 * @return array<string, string>
	 */
	public function module_columns( array $columns ): array {
		return self::insert_before(
			$columns,
			'date',
			[
				'vl_course'       => __( 'Курс', 'vl-lms' ),
				'vl_lesson_count' => __( 'Уроки', 'vl-lms' ),
			]
		);
	}

	/**
	 * @param array<string, string> $columns
	 * @return array<string, string>
	 */
	public function lesson_columns( array $columns ): array {
		return self::insert_before(
			$columns,
			'date',
			[
				'vl_course'      => __( 'Курс', 'vl-lms' ),
				'vl_module'      => __( 'Модуль', 'vl-lms' ),
				'vl_topic_count' => __( 'Теми', 'vl-lms' ),
			]
		);
	}

	/**
	 * @param array<string, string> $columns
	 * @return array<string, string>
	 */
	public function topic_columns( array $columns ): array {
		return self::insert_before(
			$columns,
			'date',
			[
				'vl_course' => __( 'Курс', 'vl-lms' ),
				'vl_lesson' => __( 'Урок', 'vl-lms' ),
			]
		);
	}

	/**
	 * @param array<string, string> $columns
	 * @return array<string, string>
	 */
	public function session_columns( array $columns ): array {
		return self::insert_before(
			$columns,
			'date',
			[
				'vl_course'           => __( 'Курс', 'vl-lms' ),
				'vl_session_delivery' => __( 'Дата проведення', 'vl-lms' ),
			]
		);
	}

	public function render_course_column( string $column, int $post_id ): void {
		if ( 'vl_course_type' !== $column ) {
			return;
		}
		echo esc_html( $this->course_type_label( $post_id ) );
	}

	/**
	 * Human label for the `_vl_course_type` meta. Missing / unknown meta is
	 * treated as self-paced, mirroring the curriculum read path where a
	 * course is cohort only when the meta is exactly `cohort`.
	 *
	 * Labels are hardcoded in Ukrainian to match the `CourseMetaBox`
	 * type dropdown — wp-admin has no `.mo` loaded for this text domain,
	 * so `__()` would surface the English source verbatim.
	 */
	private function course_type_label( int $post_id ): string {
		$type = (string) get_post_meta( $post_id, '_vl_course_type', true );

		return 'cohort' === $type ? 'Когортний' : 'Самостійний';
	}

	public function render_module_column( string $column, int $post_id ): void {
		switch ( $column ) {
			case 'vl_course':
				echo esc_html( $this->course_label_for( (int) get_post_field( 'post_parent', $post_id ), 'vl_course' ) );
				break;
			case 'vl_lesson_count':
				echo (int) $this->count_children( $post_id, 'vl_lesson' );
				break;
		}
	}

	public function render_lesson_column( string $column, int $post_id ): void {
		switch ( $column ) {
			case 'vl_course':
				echo esc_html( $this->resolve_lesson_course_label( $post_id ) );
				break;
			case 'vl_module':
				echo esc_html( $this->resolve_lesson_module_label( $post_id ) );
				break;
			case 'vl_topic_count':
				echo (int) $this->count_children( $post_id, 'vl_topic' );
				break;
		}
	}

	public function render_topic_column( string $column, int $post_id ): void {
		switch ( $column ) {
			case 'vl_course':
				$lesson_id = (int) get_post_field( 'post_parent', $post_id );
				if ( $lesson_id <= 0 ) {
					echo esc_html__( '—', 'vl-lms' );
					break;
				}
				echo esc_html( $this->resolve_lesson_course_label( $lesson_id ) );
				break;
			case 'vl_lesson':
				$lesson_id = (int) get_post_field( 'post_parent', $post_id );
				echo esc_html( $this->post_title_for( $lesson_id, 'vl_lesson' ) );
				break;
		}
	}

	public function render_session_column( string $column, int $post_id ): void {
		switch ( $column ) {
			case 'vl_course':
				echo esc_html( $this->post_title_for( (int) get_post_field( 'post_parent', $post_id ), 'vl_course' ) );
				break;
			case 'vl_session_delivery':
				echo esc_html( $this->format_scheduled_start( $post_id ) );
				break;
		}
	}

	/**
	 * Format `_vl_session_scheduled_start` (ISO 8601 UTC) using the site's
	 * configured date + time formats. Empty / unparseable values render as
	 * an em-dash so the column never collapses.
	 */
	private function format_scheduled_start( int $session_id ): string {
		$raw = (string) get_post_meta( $session_id, '_vl_session_scheduled_start', true );
		if ( '' === $raw ) {
			return __( '—', 'vl-lms' );
		}

		$timestamp = strtotime( $raw );
		if ( false === $timestamp ) {
			return __( '—', 'vl-lms' );
		}

		$date_format = (string) get_option( 'date_format', 'Y-m-d' );
		$time_format = (string) get_option( 'time_format', 'H:i' );
		$format      = trim( $date_format . ' ' . $time_format );

		return (string) wp_date( $format, $timestamp );
	}

	/**
	 * Render a "Course" dropdown above the `vl_module` list table.
	 *
	 * `restrict_manage_posts` fires for every post type; this method
	 * short-circuits unless the current screen is the modules list. The
	 * dropdown lists every `vl_course` post (any non-trashed status) and
	 * preserves the active selection on reload via the `vl_course_id`
	 * query var.
	 *
	 * The default form on `edit.php` is a GET form whose submit button is
	 * already wired ("Filter"), so we only need to emit the `<select>`
	 * — WordPress handles submission.
	 */
	public function render_module_course_filter( string $post_type ): void {
		if ( 'vl_module' !== $post_type ) {
			return;
		}

		$selected = $this->read_filter_param( self::COURSE_FILTER_PARAM );
		$courses  = $this->all_course_options();

		echo '<label class="screen-reader-text" for="' . esc_attr( self::COURSE_FILTER_PARAM ) . '">'
			. esc_html__( 'Фільтр за курсом', 'vl-lms' )
			. '</label>';
		echo '<select name="' . esc_attr( self::COURSE_FILTER_PARAM ) . '" id="' . esc_attr( self::COURSE_FILTER_PARAM ) . '">';
		echo '<option value="0">' . esc_html__( 'Усі курси', 'vl-lms' ) . '</option>';
		foreach ( $courses as $course_id => $title ) {
			$label = '' === $title ? __( '(без назви)', 'vl-lms' ) : $title;
			echo '<option value="' . esc_attr( (string) $course_id ) . '"' . selected( $selected, $course_id, false ) . '>'
				. esc_html( $label )
				. '</option>';
		}
		echo '</select>';
	}

	/**
	 * Narrow the modules list query to the chosen course when the
	 * `vl_course_id` query var is set.
	 *
	 * Guards: only the wp-admin main query for the `vl_module` list table
	 * is touched — REST, frontend, and secondary admin queries are left
	 * alone.
	 */
	public function apply_module_course_filter( WP_Query $query ): void {
		if ( ! is_admin() ) {
			return;
		}
		if ( ! $query->is_main_query() ) {
			return;
		}
		if ( 'vl_module' !== $query->get( 'post_type' ) ) {
			return;
		}

		$course_id = $this->read_filter_param( self::COURSE_FILTER_PARAM );
		if ( $course_id <= 0 ) {
			return;
		}

		$query->set( 'post_parent', $course_id );
	}

	/**
	 * Render the "Course" and "Module" dropdowns above the `vl_lesson` list
	 * table.
	 *
	 * The course dropdown mirrors the modules-list filter and shares its
	 * `vl_course_id` query var. The module dropdown pre-renders every
	 * course-attached module with a `data-course` attribute; the inline
	 * script (the `LessonMetaBox` cascade pattern) shows only the chosen
	 * course's modules, resets a selection that no longer fits, and hides
	 * the dropdown when no course is chosen or the course has no modules.
	 * The server renders the same initial state, so a module that does not
	 * belong to the selected course is never shown as selected.
	 */
	public function render_lesson_filters( string $post_type ): void {
		if ( 'vl_lesson' !== $post_type ) {
			return;
		}

		$selected_course = $this->read_filter_param( self::COURSE_FILTER_PARAM );
		$selected_module = $this->read_filter_param( self::MODULE_FILTER_PARAM );
		$courses         = $this->all_course_options();
		$modules         = $this->all_module_options();

		echo '<label class="screen-reader-text" for="' . esc_attr( self::COURSE_FILTER_PARAM ) . '">'
			. esc_html__( 'Фільтр за курсом', 'vl-lms' )
			. '</label>';
		echo '<select name="' . esc_attr( self::COURSE_FILTER_PARAM ) . '" id="' . esc_attr( self::COURSE_FILTER_PARAM ) . '">';
		echo '<option value="0">' . esc_html__( 'Усі курси', 'vl-lms' ) . '</option>';
		foreach ( $courses as $course_id => $title ) {
			$label = '' === $title ? __( '(без назви)', 'vl-lms' ) : $title;
			echo '<option value="' . esc_attr( (string) $course_id ) . '"' . selected( $selected_course, $course_id, false ) . '>'
				. esc_html( $label )
				. '</option>';
		}
		echo '</select>';

		$has_modules = false;
		foreach ( $modules as $module ) {
			if ( $selected_course > 0 && $module['course_id'] === $selected_course ) {
				$has_modules = true;
				break;
			}
		}

		echo '<label class="screen-reader-text" for="' . esc_attr( self::MODULE_FILTER_PARAM ) . '">'
			. esc_html__( 'Фільтр за модулем', 'vl-lms' )
			. '</label>';
		echo '<select name="' . esc_attr( self::MODULE_FILTER_PARAM ) . '" id="' . esc_attr( self::MODULE_FILTER_PARAM ) . '"'
			. ( $has_modules ? '' : ' style="display:none"' )
			. '>';
		echo '<option value="0">' . esc_html__( 'Усі модулі', 'vl-lms' ) . '</option>';
		foreach ( $modules as $module_id => $module ) {
			$belongs = $selected_course > 0 && $module['course_id'] === $selected_course;
			$label   = '' === $module['title'] ? __( '(без назви)', 'vl-lms' ) : $module['title'];
			echo '<option value="' . esc_attr( (string) $module_id ) . '" data-course="' . esc_attr( (string) $module['course_id'] ) . '"'
				. ( $belongs ? selected( $selected_module, $module_id, false ) : ' hidden' )
				. '>'
				. esc_html( $label )
				. '</option>';
		}
		echo '</select>';

		echo "<script>\n"
			. "(function(){\n"
			. "  var courseSelect = document.getElementById('vl_course_id');\n"
			. "  var moduleSelect = document.getElementById('vl_module_id');\n"
			. "  if (!courseSelect || !moduleSelect) { return; }\n"
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
			. "    moduleSelect.style.display = (courseId !== '0' && visible > 0) ? '' : 'none';\n"
			. "  }\n"
			. "  courseSelect.addEventListener('change', sync);\n"
			. "  sync();\n"
			. "})();\n"
			. "</script>\n";
	}

	/**
	 * Narrow the lessons list query to the chosen course, or to one of its
	 * modules.
	 *
	 * A course matches lessons parented to the course itself (course-direct)
	 * or to any of its modules — the same walk the Course column does. A
	 * module narrows further only when it belongs to the chosen course; a
	 * stale or crafted `vl_module_id` (no course, or another course's
	 * module) is ignored so the result always matches the dropdowns.
	 *
	 * Guards mirror {@see self::apply_module_course_filter()}: only the
	 * wp-admin main query for the `vl_lesson` list table is touched.
	 */
	public function apply_lesson_filters( WP_Query $query ): void {
		if ( ! is_admin() ) {
			return;
		}
		if ( ! $query->is_main_query() ) {
			return;
		}
		if ( 'vl_lesson' !== $query->get( 'post_type' ) ) {
			return;
		}

		$course_id = $this->read_filter_param( self::COURSE_FILTER_PARAM );
		if ( $course_id <= 0 ) {
			return;
		}

		$module_ids = $this->module_ids_for_course( $course_id );
		$module_id  = $this->read_filter_param( self::MODULE_FILTER_PARAM );
		if ( $module_id > 0 && in_array( $module_id, $module_ids, true ) ) {
			$query->set( 'post_parent', $module_id );
			return;
		}

		$query->set( 'post_parent__in', array_merge( [ $course_id ], $module_ids ) );
	}

	/**
	 * Read an integer filter GET param (`vl_course_id`, `vl_module_id`).
	 * Nonces are skipped intentionally — this is a read-only narrowing of
	 * an already-permission-gated admin list query (the screen capability
	 * gates access; bookmarking a URL does not bypass anything).
	 */
	private function read_filter_param( string $param ): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter; see method docblock.
		$raw = $_GET[ $param ] ?? null;
		if ( null === $raw ) {
			return 0;
		}
		return absint( wp_unslash( (string) $raw ) );
	}

	/**
	 * Fetch every `vl_course` post keyed by id with its title, sorted by
	 * title. Trashed and auto-draft posts are excluded; everything else
	 * (published / draft / pending / future / private) is included so
	 * authors can find modules attached to courses that are not yet live.
	 *
	 * @return array<int, string>
	 */
	protected function all_course_options(): array {
		$query = new WP_Query(
			[
				'post_type'              => 'vl_course',
				'post_status'            => [ 'publish', 'draft', 'pending', 'future', 'private' ],
				'posts_per_page'         => -1,
				'orderby'                => 'title',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'suppress_filters'       => true,
			]
		);

		$out = [];
		if ( ! is_array( $query->posts ) ) {
			return $out;
		}
		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			$out[ (int) $post->ID ] = (string) $post->post_title;
		}
		return $out;
	}

	/**
	 * Fetch every course-attached `vl_module` post keyed by id, with its
	 * title and parent course id, in curriculum order (`menu_order`, then
	 * title). Unattached modules (`post_parent = 0`) are skipped — the
	 * lesson filter only offers modules under a chosen course. Statuses
	 * match {@see self::all_course_options()}.
	 *
	 * @return array<int, array{title: string, course_id: int}>
	 */
	protected function all_module_options(): array {
		$query = new WP_Query(
			[
				'post_type'              => 'vl_module',
				'post_status'            => [ 'publish', 'draft', 'pending', 'future', 'private' ],
				'posts_per_page'         => -1,
				'orderby'                => [
					'menu_order' => 'ASC',
					'title'      => 'ASC',
				],
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'suppress_filters'       => true,
			]
		);

		$out = [];
		if ( ! is_array( $query->posts ) ) {
			return $out;
		}
		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof \WP_Post || (int) $post->post_parent <= 0 ) {
				continue;
			}
			$out[ (int) $post->ID ] = [
				'title'     => (string) $post->post_title,
				'course_id' => (int) $post->post_parent,
			];
		}
		return $out;
	}

	/**
	 * Ids of every `vl_module` parented to `$course_id`, trashed ones
	 * included: a lesson under a trashed module still shows that course in
	 * the Course column, so the course filter must still match it.
	 *
	 * @return list<int>
	 */
	protected function module_ids_for_course( int $course_id ): array {
		$query = new WP_Query(
			[
				'post_type'              => 'vl_module',
				'post_parent'            => $course_id,
				'post_status'            => [ 'publish', 'draft', 'pending', 'future', 'private', 'trash' ],
				'fields'                 => 'ids',
				'posts_per_page'         => -1,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'suppress_filters'       => true,
			]
		);

		$ids = [];
		if ( ! is_array( $query->posts ) ) {
			return $ids;
		}
		foreach ( $query->posts as $post ) {
			$ids[] = $post instanceof \WP_Post ? (int) $post->ID : (int) $post;
		}
		return $ids;
	}

	private function resolve_lesson_course_label( int $lesson_id ): string {
		$parent_id   = (int) get_post_field( 'post_parent', $lesson_id );
		$parent_type = $parent_id > 0 ? (string) get_post_type( $parent_id ) : '';

		if ( 'vl_course' === $parent_type ) {
			return $this->post_title_for( $parent_id, 'vl_course' );
		}

		if ( 'vl_module' === $parent_type ) {
			$course_id = (int) get_post_field( 'post_parent', $parent_id );
			return $this->post_title_for( $course_id, 'vl_course' );
		}

		return __( '—', 'vl-lms' );
	}

	private function resolve_lesson_module_label( int $lesson_id ): string {
		$parent_id   = (int) get_post_field( 'post_parent', $lesson_id );
		$parent_type = $parent_id > 0 ? (string) get_post_type( $parent_id ) : '';

		if ( 'vl_module' === $parent_type ) {
			return $this->post_title_for( $parent_id, 'vl_module' );
		}

		return __( '—', 'vl-lms' );
	}

	private function course_label_for( int $course_id, string $expected_type ): string {
		return $this->post_title_for( $course_id, $expected_type );
	}

	private function post_title_for( int $post_id, string $expected_type ): string {
		if ( $post_id <= 0 ) {
			return __( '—', 'vl-lms' );
		}
		if ( get_post_type( $post_id ) !== $expected_type ) {
			return __( '—', 'vl-lms' );
		}
		// Decode entities before the caller's `esc_html()` runs: `get_the_title()`
		// texturizes, so a plain `'` arrives here as `&#8217;` and would render
		// as the literal entity once escaped again.
		$title = PlainText::from_html( (string) get_the_title( $post_id ) );
		return '' === $title ? __( '(без назви)', 'vl-lms' ) : $title;
	}

	private function count_children( int $parent_id, string $child_post_type ): int {
		if ( $parent_id <= 0 ) {
			return 0;
		}

		$query = new \WP_Query(
			[
				'post_type'              => $child_post_type,
				'post_parent'            => $parent_id,
				'post_status'            => [ 'publish', 'draft', 'pending', 'future', 'private' ],
				'fields'                 => 'ids',
				'posts_per_page'         => -1,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'suppress_filters'       => true,
			]
		);

		return is_array( $query->posts ) ? count( $query->posts ) : 0;
	}

	/**
	 * Insert `$additions` immediately before `$before_key` in `$columns`,
	 * preserving the original order. If `$before_key` is absent the
	 * additions are appended at the end.
	 *
	 * @param array<string, string> $columns
	 * @param array<string, string> $additions
	 * @return array<string, string>
	 */
	private static function insert_before( array $columns, string $before_key, array $additions ): array {
		if ( ! array_key_exists( $before_key, $columns ) ) {
			return array_merge( $columns, $additions );
		}

		$result = [];
		foreach ( $columns as $key => $label ) {
			if ( $key === $before_key ) {
				foreach ( $additions as $add_key => $add_label ) {
					$result[ $add_key ] = $add_label;
				}
			}
			$result[ $key ] = $label;
		}

		return $result;
	}
}
