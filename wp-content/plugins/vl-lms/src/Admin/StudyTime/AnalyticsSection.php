<?php

declare(strict_types=1);

namespace VL\LMS\Admin\StudyTime;

use VL\LMS\StudyTime\Reports\StudyTimeReportQuery;
use VL\LMS\StudyTime\Support\DurationFormatter;
use WP_Post;
use WP_Query;

/**
 * The «Час навчання» section of the wp-admin Аналітика page: how long the
 * average learner spends in each published course, and — for one course at a
 * time — how that splits across its lessons.
 *
 * Hooked from `StudyTime\StudyTimeProvider` on `vl_lms_admin_analytics_sections`
 * (`docs/CONTRACTS.md` → wp-admin extension points). That action fires on both
 * branches of the page, including the one taken while `vl_user_activity_daily`
 * is still empty, because this section reads the feature's own tables and has
 * no nightly rollup to wait for.
 *
 * Averages are over the learners who have time, never over everyone enrolled
 * (`docs/DECISIONS.md` 2026-09-15), which is what «Студентів з часом» reports
 * beside each figure.
 *
 * Not `final`: the one `WP_Query` sits behind a protected seam so the section
 * can be rendered in a unit test, the way `Admin\Columns\CurriculumListColumns`
 * and `Admin\Analytics\AnalyticsPage` are (`docs/TESTING.md`).
 *
 * @author Tymofii Synianskyi
 */
class AnalyticsSection {

	/**
	 * The `GET` parameter carrying the chosen course. Read-only: it selects
	 * what to display and changes nothing, so it needs no nonce.
	 */
	public const string COURSE_PARAM = 'vl_study_course';

	/**
	 * The page this section lives on, so its own form posts back here rather
	 * than to the admin root. Mirrors `Admin\Menu\AdminMenuProvider`'s
	 * analytics slug — a URL, not a class this feature may reach into.
	 */
	private const string PAGE_SLUG = 'vl-lms-analytics';

	public function __construct( private readonly StudyTimeReportQuery $reports ) {
	}

	public function render(): void {
		$courses = $this->published_courses();

		$reports  = [];
		$has_time = false;
		foreach ( $courses as $course_id => $title ) {
			$report                = $this->reports->for_course( $course_id );
			$reports[ $course_id ] = $report;
			if ( (int) $report['learners_with_time'] > 0 ) {
				$has_time = true;
			}
		}

		echo '<section class="vl-admin-card">';
		echo '<h2>' . esc_html__( 'Час навчання', 'vl-lms' ) . '</h2>';

		if ( ! $has_time ) {
			echo '<p>' . esc_html__( 'Час навчання ще не зафіксовано.', 'vl-lms' ) . '</p>';
			echo '</section>';
			return;
		}

		$this->render_overview( $courses, $reports );

		$selected = $this->selected_course();
		$this->render_selector( $courses, $selected );

		if ( isset( $reports[ $selected ] ) ) {
			$this->render_lessons( $reports[ $selected ] );
		}

		echo '</section>';
	}

	/**
	 * @param array<int, string>                $courses
	 * @param array<int, array<string, mixed>>  $reports
	 */
	private function render_overview( array $courses, array $reports ): void {
		echo '<table class="widefat striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Курс', 'vl-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Студентів з часом', 'vl-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Середній час', 'vl-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Відео', 'vl-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Читання', 'vl-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Тести', 'vl-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Сесії', 'vl-lms' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $courses as $course_id => $title ) {
			$report = $reports[ $course_id ] ?? [];
			echo '<tr>';
			echo '<td>' . esc_html( $title ) . '</td>';
			echo '<td>' . esc_html( (string) (int) ( $report['learners_with_time'] ?? 0 ) ) . '</td>';
			echo '<td>' . esc_html( DurationFormatter::uk( (int) ( $report['avg_total'] ?? 0 ) ) ) . '</td>';
			echo '<td>' . esc_html( DurationFormatter::uk( (int) ( $report['avg_video'] ?? 0 ) ) ) . '</td>';
			echo '<td>' . esc_html( DurationFormatter::uk( (int) ( $report['avg_reading'] ?? 0 ) ) ) . '</td>';
			echo '<td>' . esc_html( DurationFormatter::uk( (int) ( $report['avg_quiz'] ?? 0 ) ) ) . '</td>';
			echo '<td>' . esc_html( DurationFormatter::uk( (int) ( $report['avg_session'] ?? 0 ) ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * @param array<int, string> $courses
	 */
	private function render_selector( array $courses, int $selected ): void {
		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE_SLUG ) . '" />';
		echo '<label for="' . esc_attr( self::COURSE_PARAM ) . '">' . esc_html__( 'Курс для деталізації за уроками', 'vl-lms' ) . '</label> ';
		echo '<select name="' . esc_attr( self::COURSE_PARAM ) . '" id="' . esc_attr( self::COURSE_PARAM ) . '">';
		echo '<option value="0">' . esc_html__( '— Оберіть курс —', 'vl-lms' ) . '</option>';
		foreach ( $courses as $course_id => $title ) {
			echo '<option value="' . esc_attr( (string) $course_id ) . '"' . selected( $selected, $course_id, false ) . '>'
				. esc_html( $title )
				. '</option>';
		}
		echo '</select> ';
		submit_button( __( 'Показати', 'vl-lms' ), 'secondary', '', false );
		echo '</form>';
	}

	/**
	 * @param array<string, mixed> $report
	 */
	private function render_lessons( array $report ): void {
		$lessons = is_array( $report['lessons'] ?? null ) ? $report['lessons'] : [];

		if ( [] === $lessons ) {
			echo '<p>' . esc_html__( 'Час навчання ще не зафіксовано.', 'vl-lms' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Урок', 'vl-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Середній час', 'vl-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Студентів', 'vl-lms' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $lessons as $lesson ) {
			if ( ! is_array( $lesson ) ) {
				continue;
			}
			echo '<tr>';
			echo '<td>' . esc_html( (string) ( $lesson['title'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( DurationFormatter::uk( (int) ( $lesson['avg_total'] ?? 0 ) ) ) . '</td>';
			echo '<td>' . esc_html( (string) (int) ( $lesson['learners'] ?? 0 ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * The chosen course id, or 0.
	 *
	 * A read-only display filter, like the course dropdown above the modules
	 * list (`Admin\Columns\CurriculumListColumns::read_filter_param()`): it
	 * selects what to show and writes nothing, so it carries no nonce.
	 */
	private function selected_course(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display filter; see method docblock.
		$raw = $_GET[ self::COURSE_PARAM ] ?? null;
		if ( null === $raw ) {
			return 0;
		}
		return absint( wp_unslash( (string) $raw ) );
	}

	/**
	 * Published courses as `id => title`, in title order.
	 *
	 * @return array<int, string>
	 */
	protected function published_courses(): array {
		$query = new WP_Query(
			[
				'post_type'              => 'vl_course',
				'post_status'            => 'publish',
				'posts_per_page'         => -1,
				'orderby'                => 'title',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			]
		);

		$out = [];
		if ( ! is_array( $query->posts ) ) {
			return $out;
		}
		foreach ( $query->posts as $post ) {
			if ( $post instanceof WP_Post ) {
				$out[ (int) $post->ID ] = (string) $post->post_title;
			}
		}
		return $out;
	}
}
