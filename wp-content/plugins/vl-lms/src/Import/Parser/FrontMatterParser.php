<?php

declare(strict_types=1);

namespace VL\LMS\Import\Parser;

use VL\LMS\Import\Document\FrontMatter;
use VL\LMS\Import\Document\FrontMatterEntry;
use VL\LMS\Import\Document\FrontMatterType;
use VL\LMS\Import\Issue\ImportIssue;
use VL\LMS\Import\Issue\IssueList;

/**
 * Parses the flat YAML subset of the course frontmatter
 * (`docs/features/course-import/format/COURSE-FORMAT.md` §2).
 *
 * Supported, and nothing else (`docs/DECISIONS.md` 2026-09-11 — no YAML
 * library): blank lines, `#` comment lines, and `key: value` lines at column
 * 0 whose value is a double-quoted string (escapes `\"` and `\\` only), a
 * decimal int, a `YYYY-MM-DD` calendar date, an inline `[a, b]` list of bare
 * items, or a bare string — each optionally followed by whitespace and a
 * `# comment`. Anything YAML would read differently (single quotes, block
 * scalars, anchors, nested structures …) is a format error with its line,
 * never a guess.
 *
 * @author Tymofii Synianskyi
 */
final class FrontMatterParser {

	public const INVALID_LINE  = 'frontmatter.invalid_line';
	public const INVALID_VALUE = 'frontmatter.invalid_value';
	public const DUPLICATE_KEY = 'frontmatter.duplicate_key';

	/**
	 * @param list<string> $lines      The lines between the opening and the closing `---`, without line breaks.
	 * @param int          $first_line The file line of `$lines[0]`.
	 */
	public function parse( array $lines, int $first_line, IssueList $issues ): FrontMatter {
		$entries = [];
		$seen    = [];

		foreach ( $lines as $index => $text ) {
			$line = $first_line + $index;

			if ( '' === trim( $text ) || 1 === preg_match( '/^[ \t]*#/', $text ) ) {
				continue;
			}

			if ( 1 !== preg_match( '/^([A-Za-z0-9_]+):(?:[ \t]+(.*))?$/su', $text, $matches ) ) {
				$issues->add(
					ImportIssue::error(
						self::INVALID_LINE,
						$line,
						__( 'Рядок frontmatter має бути парою «ключ: значення» (ключ з першої позиції, після двокрапки пробіл), коментарем «#» або порожнім.', 'vl-lms' )
					)
				);
				continue;
			}

			$key = $matches[1];
			if ( isset( $seen[ $key ] ) ) {
				$issues->add(
					ImportIssue::error(
						self::DUPLICATE_KEY,
						$line,
						/* translators: %s: frontmatter key */
						sprintf( __( 'Ключ «%s» вказано двічі.', 'vl-lms' ), $key )
					)
				);
				continue;
			}
			$seen[ $key ] = true;

			$entry = $this->entry( $key, trim( $matches[2] ?? '' ), $line );
			if ( is_string( $entry ) ) {
				$issues->add( ImportIssue::error( self::INVALID_VALUE, $line, $entry ) );
				continue;
			}

			$entries[] = $entry;
		}

		return new FrontMatter( $entries );
	}

	/**
	 * @return FrontMatterEntry|string The entry, or the error message when the value is outside the subset.
	 */
	private function entry( string $key, string $raw, int $line ): FrontMatterEntry|string {
		if ( '' === $raw || str_starts_with( $raw, '#' ) ) {
			/* translators: %s: frontmatter key */
			return sprintf( __( 'Ключ «%s» не має значення. Вкажіть значення або видаліть рядок.', 'vl-lms' ), $key );
		}

		if ( str_starts_with( $raw, '"' ) ) {
			return $this->double_quoted( $key, $raw, $line );
		}

		if ( str_starts_with( $raw, "'" ) ) {
			/* translators: %s: frontmatter key */
			return sprintf( __( 'Значення ключа «%s» взято в одинарні лапки. Використовуйте подвійні лапки.', 'vl-lms' ), $key );
		}

		$value = (string) preg_replace( '/[ \t]+#.*$/su', '', $raw );

		if ( str_starts_with( $value, '[' ) ) {
			return $this->inline_list( $key, $value, $line );
		}

		if ( ! $this->is_plain_scalar( $value ) ) {
			/* translators: %s: frontmatter key */
			return sprintf( __( 'Значення ключа «%s» починається зі спецсимволу або містить «: ». Візьміть його в подвійні лапки.', 'vl-lms' ), $key );
		}

		if ( 1 === preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $date ) ) {
			if ( ! checkdate( (int) $date[2], (int) $date[3], (int) $date[1] ) ) {
				/* translators: 1: frontmatter key, 2: the date as written */
				return sprintf( __( 'Значення ключа «%1$s»: дати %2$s не існує.', 'vl-lms' ), $key, $value );
			}

			return new FrontMatterEntry( $key, $value, FrontMatterType::DATE, $line );
		}

		if ( 1 === preg_match( '/^-?(0|[1-9][0-9]*)$/', $value ) ) {
			$int = filter_var( $value, FILTER_VALIDATE_INT );
			if ( false === $int ) {
				/* translators: %s: frontmatter key */
				return sprintf( __( 'Значення ключа «%s»: число завелике.', 'vl-lms' ), $key );
			}

			return new FrontMatterEntry( $key, $int, FrontMatterType::INT, $line );
		}

		return new FrontMatterEntry( $key, $value, FrontMatterType::STRING, $line );
	}

	private function double_quoted( string $key, string $raw, int $line ): FrontMatterEntry|string {
		$value  = '';
		$length = strlen( $raw );

		// Byte-wise is safe for UTF-8: `"` and `\` never occur inside a multibyte sequence.
		for ( $i = 1; $i < $length; $i++ ) {
			$char = $raw[ $i ];

			if ( '\\' === $char ) {
				$next = $raw[ $i + 1 ] ?? '';
				if ( '"' !== $next && '\\' !== $next ) {
					/* translators: %s: frontmatter key */
					return sprintf( __( 'Значення ключа «%s»: у лапках дозволені лише екранування \" і \\\\.', 'vl-lms' ), $key );
				}
				$value .= $next;
				++$i;
				continue;
			}

			if ( '"' === $char ) {
				$rest = substr( $raw, $i + 1 );
				if ( '' !== $rest && 1 !== preg_match( '/^[ \t]+#/', $rest ) ) {
					/* translators: %s: frontmatter key */
					return sprintf( __( 'Значення ключа «%s»: після закривної лапки дозволений лише коментар « #».', 'vl-lms' ), $key );
				}

				return new FrontMatterEntry( $key, $value, FrontMatterType::STRING, $line );
			}

			$value .= $char;
		}

		/* translators: %s: frontmatter key */
		return sprintf( __( 'Значення ключа «%s»: лапки не закрито.', 'vl-lms' ), $key );
	}

	private function inline_list( string $key, string $value, int $line ): FrontMatterEntry|string {
		/* translators: %s: frontmatter key */
		$malformed = sprintf( __( 'Значення ключа «%s»: список має виглядати як [a, b] — без лапок, порожніх і вкладених елементів.', 'vl-lms' ), $key );

		if ( ! str_ends_with( $value, ']' ) ) {
			return $malformed;
		}

		$inner = trim( substr( $value, 1, -1 ) );
		if ( '' === $inner ) {
			return new FrontMatterEntry( $key, [], FrontMatterType::LIST, $line );
		}

		$items = [];
		foreach ( explode( ',', $inner ) as $item ) {
			$item = trim( $item );
			if ( '' === $item || ! $this->is_plain_scalar( $item ) || 1 === preg_match( '/[\[\]{}"\']/', $item ) ) {
				return $malformed;
			}
			$items[] = $item;
		}

		return new FrontMatterEntry( $key, $items, FrontMatterType::LIST, $line );
	}

	/**
	 * Whether YAML would read `$value` as a plain (unquoted) string: it must not
	 * start with an indicator character and must not contain a mapping `: `.
	 */
	private function is_plain_scalar( string $value ): bool {
		if ( 1 === preg_match( '/^[\'"\[\]{}&*!|>%@`]/', $value ) ) {
			return false;
		}

		foreach ( [ '- ', '? ', ': ' ] as $indicator ) {
			if ( str_starts_with( $value, $indicator ) ) {
				return false;
			}
		}

		return ! str_contains( $value, ': ' ) && ! str_ends_with( $value, ':' ) && '-' !== $value;
	}
}
