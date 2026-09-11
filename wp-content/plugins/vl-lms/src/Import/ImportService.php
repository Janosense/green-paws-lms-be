<?php

declare(strict_types=1);

namespace VL\LMS\Import;

use LogicException;
use VL\LMS\Import\Convert\CourseHtmlBuilder;
use VL\LMS\Import\Convert\LessonHtmlBuilder;
use VL\LMS\Import\Convert\ModuleHtmlBuilder;
use VL\LMS\Import\Convert\RenderedHtml;
use VL\LMS\Import\Document\CourseDocument;
use VL\LMS\Import\Document\CourseVariant;
use VL\LMS\Import\Document\FrontMatter;
use VL\LMS\Import\Document\LessonSpec;
use VL\LMS\Import\Document\QuestionSpec;
use VL\LMS\Import\Document\QuizKind;
use VL\LMS\Import\Document\QuizSpec;
use VL\LMS\Import\Issue\ImportIssue;
use VL\LMS\Import\Issue\IssueList;
use VL\LMS\Import\Parser\CourseDocumentParser;
use VL\LMS\Import\Plan\AnalysisResult;
use VL\LMS\Import\Plan\CoursePlan;
use VL\LMS\Import\Plan\ImportPlan;
use VL\LMS\Import\Plan\ImportSource;
use VL\LMS\Import\Plan\LessonPlan;
use VL\LMS\Import\Plan\ModulePlan;
use VL\LMS\Import\Plan\QuestionPlan;
use VL\LMS\Import\Plan\QuizPlan;
use VL\LMS\Import\Validation\CourseLevel;
use VL\LMS\Import\Validation\CourseValidator;
use VL\LMS\Import\Write\ImportContext;
use VL\LMS\Import\Write\Importer;
use VL\LMS\Import\Write\ImportResult;

/**
 * Turns an uploaded `course.md` into an {@see ImportPlan}: parse → validate →
 * convert, and imports it on confirmation. Analysis is pure apart from reading
 * the file and the validator's term lookups, so the confirmation step
 * re-analyses the stored file and gets the plan the preview showed
 * (`docs/DECISIONS.md` 2026-09-11 — temp folder).
 *
 * The validator runs only when the parse had no errors: after a parse error
 * the tree lacks the rejected headings and their children, and validating it
 * would report errors the file does not have.
 *
 * @author Tymofii Synianskyi
 */
final class ImportService {

	public const UNREADABLE = 'document.unreadable';

	/**
	 * @param int $default_pass_percent The pass percent when the file sets no `quiz_pass_percent`; configuration, never a literal here.
	 */
	public function __construct(
		private readonly CourseDocumentParser $parser,
		private readonly CourseValidator $validator,
		private readonly CourseHtmlBuilder $course_html_builder,
		private readonly ModuleHtmlBuilder $module_html_builder,
		private readonly LessonHtmlBuilder $lesson_html_builder,
		private readonly Importer $importer,
		private readonly int $default_pass_percent
	) {
	}

	/**
	 * Re-analyses the stored file and writes its course tree. The preview has
	 * already refused a file with errors; the import refuses it again instead
	 * of trusting that, and then creates nothing.
	 *
	 * @param string $course_md_path The stored `course.md` (Sprint 1 Step 5 passes its temp-folder path).
	 */
	public function import( string $course_md_path, ImportContext $context ): ImportResult {
		$plan = $this->analyse( $course_md_path )->plan;

		if ( null === $plan ) {
			return ImportResult::failed( __( 'Файл курсу містить помилки, тому імпорт не виконано. Перевірте файл і завантажте його знову.', 'vl-lms' ), [] );
		}

		return $this->importer->run( $plan, $context );
	}

	public function analyse( string $course_md_path ): AnalysisResult {
		// A local file inside the import's temp folder, never a URL.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$contents = is_file( $course_md_path ) && is_readable( $course_md_path ) ? file_get_contents( $course_md_path ) : false;

		if ( false === $contents ) {
			$issues = new IssueList();
			$issues->add(
				ImportIssue::error(
					self::UNREADABLE,
					null,
					__( 'Не вдалося прочитати файл курсу. Завантажте його ще раз.', 'vl-lms' )
				)
			);

			return new AnalysisResult( $issues, null );
		}

		$document = $this->parser->parse( $contents );
		if ( $document->issues->has_errors() ) {
			return new AnalysisResult( $document->issues, null );
		}

		$this->validator->validate( $document );
		if ( $document->issues->has_errors() ) {
			return new AnalysisResult( $document->issues, null );
		}

		return new AnalysisResult( $document->issues, $this->plan( $document ) );
	}

	/**
	 * Builds the plan of a document without errors.
	 */
	private function plan( CourseDocument $document ): ImportPlan {
		if ( null === $document->final_quiz ) {
			throw new LogicException( 'A validated course document always has a final quiz.' );
		}

		$course_html = $this->course_html_builder->build( $document );
		$rendered    = [ $course_html ];

		$modules = [];
		foreach ( $document->modules as $module ) {
			$modules[] = new ModulePlan(
				$module->title,
				$this->module_html_builder->build( $module ),
				$module->number,
				$this->lessons( $module->lessons, $rendered ),
				null === $module->quiz ? null : $this->quiz( $module->quiz )
			);
		}

		$lessons = $this->lessons( $document->lessons, $rendered );

		return new ImportPlan(
			$this->course( $document->front_matter, $course_html->html ),
			$document->variant ?? CourseVariant::FLAT,
			$modules,
			$lessons,
			$this->quiz( $document->final_quiz ),
			RenderedHtml::join( ...$rendered )->images,
			$this->source( $document->front_matter ),
			$document->issues
		);
	}

	private function course( FrontMatter $front_matter, string $html ): CoursePlan {
		$minutes = $this->int_value( $front_matter, 'duration_minutes' );
		$tags    = $front_matter->get( 'tags' )?->value;

		return new CoursePlan(
			$this->string_value( $front_matter, 'title' ) ?? '',
			$this->string_value( $front_matter, 'slug' ) ?? '',
			$html,
			CourseLevel::from( $this->string_value( $front_matter, 'level' ) ?? '' )->difficulty_slug(),
			$this->string_value( $front_matter, 'category' ),
			is_array( $tags ) ? $tags : [],
			null === $minutes ? null : round( $minutes / 30 ) / 2,
			$this->int_value( $front_matter, 'quiz_pass_percent' ) ?? $this->default_pass_percent
		);
	}

	private function source( FrontMatter $front_matter ): ImportSource {
		return new ImportSource(
			$this->string_value( $front_matter, 'slug' ) ?? '',
			$this->int_value( $front_matter, 'version' ) ?? 0,
			$this->string_value( $front_matter, 'status' ) ?? '',
			$this->string_value( $front_matter, 'author' ) ?? '',
			$this->string_value( $front_matter, 'author_org' ),
			$this->string_value( $front_matter, 'source_type' ),
			$this->string_value( $front_matter, 'source_title' ),
			$this->string_value( $front_matter, 'source_date' )
		);
	}

	/**
	 * @param list<LessonSpec>   $lessons
	 * @param list<RenderedHtml> $rendered Collects each lesson's HTML for the image list.
	 *
	 * @return list<LessonPlan>
	 */
	private function lessons( array $lessons, array &$rendered ): array {
		$plans = [];

		foreach ( $lessons as $lesson ) {
			$html       = $this->lesson_html_builder->build( $lesson );
			$rendered[] = $html;
			$plans[]    = new LessonPlan( $lesson->title, $html->html, $lesson->number );
		}

		return $plans;
	}

	private function quiz( QuizSpec $quiz ): QuizPlan {
		$questions = array_map(
			static fn ( QuestionSpec $question ): QuestionPlan => new QuestionPlan(
				$question->text,
				$question->number,
				$question->is_multiple_choice() ? QuestionPlan::MULTIPLE_CHOICE : QuestionPlan::SINGLE_CHOICE,
				array_map(
					static fn ( array $option ): array => [
						'text'       => $option['text'],
						'is_correct' => $option['correct'],
					],
					$question->options
				),
				$question->explanation
			),
			$quiz->questions
		);

		return new QuizPlan( $quiz->section->heading, QuizKind::FINAL === $quiz->kind, $questions );
	}

	private function string_value( FrontMatter $front_matter, string $key ): ?string {
		$value = $front_matter->get( $key )?->value;

		return is_string( $value ) ? $value : null;
	}

	private function int_value( FrontMatter $front_matter, string $key ): ?int {
		$value = $front_matter->get( $key )?->value;

		return is_int( $value ) ? $value : null;
	}
}
