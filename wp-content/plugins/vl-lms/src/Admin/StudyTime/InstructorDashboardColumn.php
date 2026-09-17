<?php

declare(strict_types=1);

namespace VL\LMS\Admin\StudyTime;

use VL\LMS\StudyTime\Reports\StudyTimeReportQuery;
use VL\LMS\StudyTime\Support\DurationFormatter;

/**
 * The «Час (середній)» column on «Панель інструктора» — how long the average
 * learner of each course has studied it.
 *
 * Hooked from `StudyTime\StudyTimeProvider` on the header / cell pair `core`
 * fires inside its hand-rendered course table (`docs/CONTRACTS.md` → wp-admin
 * extension points). The two halves are a contract: exactly one `<th>` here
 * means exactly one `<td>` per row there, or the table stops lining up.
 *
 * The figures are fetched **per course, on first sight, and remembered** for
 * the rest of the request. The header hook knows no ids and the cell hook
 * arrives one row at a time, so there is nothing to batch at the moment the
 * column could batch. Pre-fetching every published course instead would be
 * one query — and wrong: this dashboard also lists an instructor's `draft`,
 * `pending` and `private` courses, which would then read «—» because they are
 * unpublished rather than because nobody studied them.
 *
 * @author Tymofii Synianskyi
 */
final class InstructorDashboardColumn {

	/**
	 * `course_id => average seconds`, filled as rows are rendered.
	 *
	 * @var array<int, int>
	 */
	private array $averages = [];

	public function __construct( private readonly StudyTimeReportQuery $reports ) {
	}

	public function header(): void {
		echo '<th>' . esc_html__( 'Час (середній)', 'vl-lms' ) . '</th>';
	}

	public function cell( int $course_id ): void {
		$seconds = $this->average_for( $course_id );

		// The em dash is the renderer's call — `DurationFormatter` never
		// returns punctuation a table cell would have to interpret.
		$label = $seconds > 0 ? DurationFormatter::uk( $seconds ) : '—';

		echo '<td>' . esc_html( $label ) . '</td>';
	}

	private function average_for( int $course_id ): int {
		if ( ! array_key_exists( $course_id, $this->averages ) ) {
			$fetched                      = $this->reports->avg_total_by_course( [ $course_id ] );
			$this->averages[ $course_id ] = (int) ( $fetched[ $course_id ] ?? 0 );
		}
		return $this->averages[ $course_id ];
	}
}
