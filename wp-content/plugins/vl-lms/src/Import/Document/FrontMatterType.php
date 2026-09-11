<?php

declare(strict_types=1);

namespace VL\LMS\Import\Document;

/**
 * The shape a frontmatter value was written in.
 *
 * Types come from the syntax, never from the key: `version: 1` is an int,
 * `version: "1"` a string. A `date` keeps its `YYYY-MM-DD` text as the value.
 * Whether a key has the right type is the validator's question.
 *
 * @author Tymofii Synianskyi
 */
enum FrontMatterType: string {

	case STRING = 'string';
	case INT    = 'int';
	case DATE   = 'date';
	case LIST   = 'list';
}
