<?php

declare(strict_types=1);

namespace VL\LMS\Admin\StudyTime;

use VL\LMS\Domain\Enrollment\Enrollment;
use VL\LMS\StudyTime\Reports\StudyTimeReportQuery;
use VL\LMS\StudyTime\Support\DurationFormatter;
use WP_Post;

/**
 * The «Час навчання» card on the wp-admin student card's Аналітика tab —
 * feature `study-time`'s first surface.
 *
 * Hooked from `StudyTime\StudyTimeProvider` on the extension point `core`
 * fires after its own «Курси студента» table
 * (`vl_lms_admin_student_detail_sections`, `docs/CONTRACTS.md`). `core` calls
 * nothing here and knows nothing about this class — that separation is the
 * whole point of the hook (`docs/DECISIONS.md` 2026-09-15).
 *
 * The enrollments arrive as the hook's second argument rather than being
 * re-read, which is also where `started_at` comes from: the column is `core`
 * data this feature only reads, and it is deliberately not on the REST wire
 * (`docs/DECISIONS.md` 2026-09-16).
 *
 * @author Tymofii Synianskyi
 */
final class StudentDetailSection {

	public function __construct( private readonly StudyTimeReportQuery $reports ) {
	}

	/**
	 * @param int             $user_id     The student whose card is open.
	 * @param list<Enrollment> $enrollments The rows `core` just rendered above.
	 */
	public function render( int $user_id, array $enrollments ): void {
		$courses = [];
		$total   = 0;

		foreach ( $enrollments as $enrollment ) {
			if ( ! $enrollment instanceof Enrollment ) {
				continue;
			}
			$report    = $this->reports->for_enrollment( $user_id, $enrollment->course_id );
			$total    += $report['total'];
			$courses[] = [
				'enrollment' => $enrollment,
				'report'     => $report,
			];
		}

		echo '<section class="vl-admin-card">';
		echo '<h2>' . esc_html__( 'Час навчання', 'vl-lms' ) . '</h2>';

		if ( 0 === $total ) {
			// Keyed on the total, never on the lesson lists: a course whose
			// time is all quizzes and sessions has no lesson rows at all
			// (`docs/DECISIONS.md` 2026-09-16), and that is data, not silence.
			echo '<p>' . esc_html__( 'Час навчання ще не зафіксовано.', 'vl-lms' ) . '</p>';
			echo '</section>';
			return;
		}

		$this->render_course_table( $courses );

		foreach ( $courses as $course ) {
			$this->render_lesson_details( $course['enrollment']->course_id, $course['report'] );
		}

		echo '</section>';
	}

	/**
	 * @param list<array{enrollment: Enrollment, report: array<string, mixed>}> $courses
	 */
	private function render_course_table( array $courses ): void {
		echo '<table class="widefat striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Курс', 'vl-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Відео', 'vl-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Читання', 'vl-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Тести', 'vl-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Сесії', 'vl-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Разом', 'vl-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Перша активність', 'vl-lms' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $courses as $course ) {
			$enrollment = $course['enrollment'];
			$report     = $course['report'];

			echo '<tr>';
			echo '<td>' . esc_html( $this->course_title( $enrollment->course_id ) ) . '</td>';
			echo '<td>' . esc_html( DurationFormatter::uk( (int) $report['video'] ) ) . '</td>';
			echo '<td>' . esc_html( DurationFormatter::uk( (int) $report['reading'] ) ) . '</td>';
			echo '<td>' . esc_html( DurationFormatter::uk( (int) $report['quiz'] ) ) . '</td>';
			echo '<td>' . esc_html( DurationFormatter::uk( (int) $report['session'] ) ) . '</td>';
			echo '<td>' . esc_html( DurationFormatter::uk( (int) $report['total'] ) ) . '</td>';
			echo '<td>' . esc_html( null === $enrollment->started_at ? '—' : $enrollment->started_at ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * One course's per-lesson table, collapsed.
	 *
	 * `<details>` is the collapsible: it needs no script and no stylesheet,
	 * which is what the area's wp-admin rule asks for. A course with no
	 * lesson rows — all its time is quiz or session — gets nothing to open.
	 *
	 * @param array<string, mixed> $report
	 */
	private function render_lesson_details( int $course_id, array $report ): void {
		$lessons = is_array( $report['lessons'] ?? null ) ? $report['lessons'] : [];
		if ( [] === $lessons ) {
			return;
		}

		echo '<details>';
		echo '<summary>' . esc_html( $this->course_title( $course_id ) ) . '</summary>';
		echo '<table class="widefat striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Урок', 'vl-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Відео', 'vl-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Читання', 'vl-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Разом', 'vl-lms' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $lessons as $lesson ) {
			if ( ! is_array( $lesson ) ) {
				continue;
			}
			echo '<tr>';
			echo '<td>' . esc_html( (string) ( $lesson['title'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( DurationFormatter::uk( (int) ( $lesson['video'] ?? 0 ) ) ) . '</td>';
			echo '<td>' . esc_html( DurationFormatter::uk( (int) ( $lesson['reading'] ?? 0 ) ) ) . '</td>';
			echo '<td>' . esc_html( DurationFormatter::uk( (int) ( $lesson['total'] ?? 0 ) ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</details>';
	}

	/**
	 * The same title and the same deleted-course fallback the page's own
	 * «Курси студента» table uses, so one card cannot name a course
	 * differently from the card above it.
	 */
	private function course_title( int $course_id ): string {
		$post = get_post( $course_id );
		if ( $post instanceof WP_Post ) {
			return (string) $post->post_title;
		}
		/* translators: %d: course ID */
		return sprintf( __( '#%d (видалено)', 'vl-lms' ), $course_id );
	}
}
