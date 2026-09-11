<?php

declare(strict_types=1);

namespace VL\LMS\Import\Plan;

use VL\LMS\Import\Convert\ImageRef;
use VL\LMS\Import\Document\CourseVariant;
use VL\LMS\Import\Issue\IssueList;

/**
 * Everything the importer needs to create one course, and nothing else.
 *
 * Built only from a file without errors; in memory only. The same file
 * always gives the same plan (course-import FEATURE.md → Invariants).
 *
 * @author Tymofii Synianskyi
 */
final readonly class ImportPlan {

	/**
	 * @param CoursePlan       $course     The course post.
	 * @param CourseVariant    $variant    `flat` also when the file has no module or lesson at all.
	 * @param list<ModulePlan> $modules    Modules variant.
	 * @param list<LessonPlan> $lessons    Lessons directly under the course (a course without modules).
	 * @param QuizPlan         $final_quiz «Підсумковий тест».
	 * @param list<ImageRef>   $images     Every `assets/…` image of the course and its lessons, once per path.
	 * @param ImportSource     $source     The file part of `_vl_import_source`.
	 * @param IssueList        $issues     The warnings and notes of the analysis.
	 */
	public function __construct(
		public CoursePlan $course,
		public CourseVariant $variant,
		public array $modules,
		public array $lessons,
		public QuizPlan $final_quiz,
		public array $images,
		public ImportSource $source,
		public IssueList $issues
	) {
	}

	/**
	 * What the preview's summary shows.
	 *
	 * @return array{modules: int, lessons: int, quizzes: int, questions: int, images: int}
	 */
	public function counts(): array {
		$lessons = count( $this->lessons );
		$quizzes = [ $this->final_quiz ];

		foreach ( $this->modules as $module ) {
			$lessons += count( $module->lessons );
			if ( null !== $module->quiz ) {
				$quizzes[] = $module->quiz;
			}
		}

		$questions = 0;
		foreach ( $quizzes as $quiz ) {
			$questions += count( $quiz->questions );
		}

		return [
			'modules'   => count( $this->modules ),
			'lessons'   => $lessons,
			'quizzes'   => count( $quizzes ),
			'questions' => $questions,
			'images'    => count( $this->images ),
		];
	}
}
