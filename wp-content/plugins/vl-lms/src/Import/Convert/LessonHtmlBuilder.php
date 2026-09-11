<?php

declare(strict_types=1);

namespace VL\LMS\Import\Convert;

use VL\LMS\Import\Document\LessonSpec;
use VL\LMS\Import\Document\SectionSpec;

/**
 * The `post_content` of an imported `vl_lesson`: every section the lesson
 * has, in the fixed order «Цілі уроку», «Зміст», «Ключові висновки»,
 * «Матеріали до уроку», each as an `<h2>` followed by its rendered body
 * (`docs/DECISIONS.md` 2026-09-11 — lessons as plain HTML).
 *
 * The `<h2>` text is the section heading from the file, which the parser
 * accepts only in its fixed spelling.
 *
 * @author Tymofii Synianskyi
 */
final class LessonHtmlBuilder {

	public function __construct(
		private readonly MarkdownToHtml $markdown_to_html
	) {
	}

	public function build( LessonSpec $lesson ): RenderedHtml {
		$parts    = [];
		$sections = [ $lesson->objectives, $lesson->content, $lesson->takeaways, $lesson->materials ];

		foreach ( $sections as $section ) {
			if ( null !== $section ) {
				$parts[] = $this->section( $section );
			}
		}

		return RenderedHtml::join( ...$parts );
	}

	private function section( SectionSpec $section ): RenderedHtml {
		return RenderedHtml::join(
			new RenderedHtml( '<h2>' . esc_html( $section->heading ) . "</h2>\n", [] ),
			$this->markdown_to_html->render( $section->body, $section->body_line )
		);
	}
}
