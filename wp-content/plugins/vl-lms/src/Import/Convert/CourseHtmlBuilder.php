<?php

declare(strict_types=1);

namespace VL\LMS\Import\Convert;

use VL\LMS\Import\Document\CourseDocument;
use VL\LMS\Import\Document\SectionSpec;

/**
 * The `post_content` of an imported `vl_course`: «Про курс», «Мета курсу»
 * and, when the file has it, «Література» as a trailing section
 * (`docs/DECISIONS.md` 2026-09-11 — scope, lessons as plain HTML).
 *
 * «Про курс» opens the description without a heading: the course landing
 * page already titles the description «Про курс». The other two sections
 * get an `<h2>` with the heading from the file.
 *
 * @author Tymofii Synianskyi
 */
final class CourseHtmlBuilder {

	public function __construct(
		private readonly MarkdownToHtml $markdown_to_html
	) {
	}

	public function build( CourseDocument $document ): RenderedHtml {
		$parts = [];

		if ( null !== $document->about ) {
			$parts[] = $this->markdown_to_html->render( $document->about->body, $document->about->body_line );
		}
		if ( null !== $document->goals ) {
			$parts[] = $this->section( $document->goals );
		}
		if ( null !== $document->literature ) {
			$parts[] = $this->section( $document->literature );
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
