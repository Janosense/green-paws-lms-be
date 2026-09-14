<?php

declare(strict_types=1);

namespace VL\LMS\Import;

use VL\LMS\Admin\Import\ImportFormHandler;
use VL\LMS\Admin\Import\ImportPage;
use VL\LMS\Admin\Import\InstructorCandidates;
use VL\LMS\Import\Convert\CourseHtmlBuilder;
use VL\LMS\Import\Convert\LessonHtmlBuilder;
use VL\LMS\Import\Convert\MarkdownToHtml;
use VL\LMS\Import\Convert\ModuleHtmlBuilder;
use VL\LMS\Import\Parser\CourseDocumentParser;
use VL\LMS\Import\Parser\FrontMatterParser;
use VL\LMS\Import\Parser\QuizBlockParser;
use VL\LMS\Import\Storage\ImportConfig;
use VL\LMS\Import\Storage\TempStore;
use VL\LMS\Import\Storage\UploadIntake;
use VL\LMS\Import\Validation\CourseValidator;
use VL\LMS\Import\Write\Importer;
use VL\LMS\Import\Write\MediaImporter;
use VL\LMS\Support\Logger;

/**
 * The bootstrap of the `course-import` feature: one registration in
 * `Plugin::build_container()`, one `boot()` call in `Plugin::boot()`, and the
 * page `Admin\Menu\AdminMenuProvider` puts in the LMS menu
 * (`wp-content/plugins/vl-lms/CLAUDE.md` → Feature isolation).
 *
 * `boot()` builds nothing: it runs at `plugins_loaded`, before a theme can add
 * its `vl_lms/import/*` filters, so the feature's services are built on first
 * use — by the menu on `init`, or by `admin_init` for the `admin-post.php`
 * handlers. `admin-post.php` fires `admin_init` before `admin_post_{action}`.
 *
 * @author Tymofii Synianskyi
 */
final class ImportProvider {

	private ?ImportConfig $config = null;

	private ?TempStore $store = null;

	private ?ImportService $service = null;

	private ?MediaImporter $media = null;

	private ?ImportPage $page = null;

	private ?ImportFormHandler $form_handler = null;

	public function boot(): void {
		add_action( 'admin_init', [ $this, 'register_handlers' ] );
	}

	/**
	 * Wires the three `admin-post.php` handlers of the import screens onto one
	 * handler instance.
	 */
	public function register_handlers(): void {
		$handler = $this->form_handler();

		add_action( 'admin_post_' . ImportFormHandler::UPLOAD_ACTION, [ $handler, 'handle_upload' ] );
		add_action( 'admin_post_' . ImportFormHandler::CONFIRM_ACTION, [ $handler, 'handle_confirm' ] );
		add_action( 'admin_post_' . ImportFormHandler::DISCARD_ACTION, [ $handler, 'handle_discard' ] );
	}

	/**
	 * The wp-admin «Імпорт курсу» screen, for the LMS menu.
	 */
	public function page(): ImportPage {
		return $this->page ??= new ImportPage( $this->config(), $this->store(), $this->service(), $this->media(), new InstructorCandidates() );
	}

	private function form_handler(): ImportFormHandler {
		return $this->form_handler ??= new ImportFormHandler(
			new UploadIntake( $this->config(), $this->store() ),
			$this->store(),
			$this->service(),
			$this->config(),
			new InstructorCandidates(),
			new Logger()
		);
	}

	/**
	 * The analysis and import pipeline, shared by the screens and the
	 * confirmation handler so one request builds it once.
	 */
	private function service(): ImportService {
		if ( null === $this->service ) {
			$markdown_to_html = new MarkdownToHtml();

			$this->service = new ImportService(
				new CourseDocumentParser( new FrontMatterParser(), new QuizBlockParser() ),
				new CourseValidator( $markdown_to_html ),
				new CourseHtmlBuilder( $markdown_to_html ),
				new ModuleHtmlBuilder(),
				new LessonHtmlBuilder( $markdown_to_html ),
				new Importer( $this->media() ),
				$this->config()->default_pass_percent
			);
		}

		return $this->service;
	}

	/**
	 * One media importer: the preview compares the folder with it, and the
	 * import uploads with it.
	 */
	private function media(): MediaImporter {
		return $this->media ??= new MediaImporter();
	}

	/**
	 * Read once per request, the first time a screen or a handler needs it.
	 */
	private function config(): ImportConfig {
		return $this->config ??= ImportConfig::from_filters();
	}

	private function store(): TempStore {
		return $this->store ??= new TempStore( $this->config() );
	}
}
