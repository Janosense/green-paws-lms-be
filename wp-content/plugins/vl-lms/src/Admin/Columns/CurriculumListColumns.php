<?php

declare(strict_types=1);

namespace VL\LMS\Admin\Columns;

use WP_Query;

/**
 * Adds parent-context columns to the wp-admin list tables for the flat
 * course-bound CPTs `vl_module`, `vl_lesson`, `vl_topic`, and `vl_session`.
 *
 * The CPTs are flat (`hierarchical: false`); their relationships live in
 * `post_parent`. Without these columns an editor opening "Lessons" sees only
 * titles and dates and has no way to tell which course or module a lesson
 * belongs to without clicking through. The columns surface:
 *
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
 * `parse_query` when the dropdown is set.
 *
 * @author Tymofii Synianskyi
 */
class CurriculumListColumns {

	private const string MODULE_COURSE_FILTER_PARAM = 'vl_course_id';

	public function boot(): void {
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
				'vl_course'       => __( 'Course', 'vl-lms' ),
				'vl_lesson_count' => __( 'Lessons', 'vl-lms' ),
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
				'vl_course'      => __( 'Course', 'vl-lms' ),
				'vl_module'      => __( 'Module', 'vl-lms' ),
				'vl_topic_count' => __( 'Topics', 'vl-lms' ),
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
				'vl_course' => __( 'Course', 'vl-lms' ),
				'vl_lesson' => __( 'Lesson', 'vl-lms' ),
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
				'vl_course'           => __( 'Course', 'vl-lms' ),
				'vl_session_delivery' => __( 'Date of delivery', 'vl-lms' ),
			]
		);
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

		$selected = $this->read_course_filter_param();
		$courses  = $this->all_course_options();

		echo '<label class="screen-reader-text" for="' . esc_attr( self::MODULE_COURSE_FILTER_PARAM ) . '">'
			. esc_html__( 'Filter by course', 'vl-lms' )
			. '</label>';
		echo '<select name="' . esc_attr( self::MODULE_COURSE_FILTER_PARAM ) . '" id="' . esc_attr( self::MODULE_COURSE_FILTER_PARAM ) . '">';
		echo '<option value="0">' . esc_html__( 'All courses', 'vl-lms' ) . '</option>';
		foreach ( $courses as $course_id => $title ) {
			$label = '' === $title ? __( '(no title)', 'vl-lms' ) : $title;
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

		$course_id = $this->read_course_filter_param();
		if ( $course_id <= 0 ) {
			return;
		}

		$query->set( 'post_parent', $course_id );
	}

	/**
	 * Read the `vl_course_id` GET param. Nonces are skipped intentionally —
	 * this is a read-only narrowing of an already-permission-gated admin
	 * list query (the screen capability gates access; bookmarking a URL
	 * does not bypass anything).
	 */
	private function read_course_filter_param(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter; see method docblock.
		$raw = $_GET[ self::MODULE_COURSE_FILTER_PARAM ] ?? null;
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
		$title = (string) get_the_title( $post_id );
		return '' === $title ? __( '(no title)', 'vl-lms' ) : $title;
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
