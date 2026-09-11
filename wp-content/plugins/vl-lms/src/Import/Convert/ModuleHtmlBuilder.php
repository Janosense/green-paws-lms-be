<?php

declare(strict_types=1);

namespace VL\LMS\Import\Convert;

use VL\LMS\Import\Document\ModuleSpec;

/**
 * The `post_content` of an imported `vl_module`: its title as one paragraph
 * (`docs/DECISIONS.md` 2026-09-11 — lessons as plain HTML).
 *
 * The title is plain text, as in `post_title`, so it is escaped rather than
 * rendered as Markdown.
 *
 * @author Tymofii Synianskyi
 */
final class ModuleHtmlBuilder {

	public function build( ModuleSpec $module ): string {
		return '<p>' . esc_html( $module->title ) . "</p>\n";
	}
}
