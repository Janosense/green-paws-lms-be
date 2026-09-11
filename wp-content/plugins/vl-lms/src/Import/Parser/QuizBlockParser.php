<?php

declare(strict_types=1);

namespace VL\LMS\Import\Parser;

use VL\LMS\Import\Document\QuestionSpec;
use VL\LMS\Import\Issue\ImportIssue;
use VL\LMS\Import\Issue\IssueList;

/**
 * Parses the body of a `## Тест модуля N` / `## Підсумковий тест` section into
 * question blocks (COURSE-FORMAT.md §5).
 *
 * A question is a `**N. текст**` line; `- [x] текст` / `- [ ] текст` lines add
 * options to it until its single `> Пояснення: текст` line. Blank lines may
 * sit anywhere between those parts. Every other non-blank line is a
 * `quiz.unexpected_line` error — one issue per contiguous block, so a stray
 * paragraph is reported once. Question counts, correct options and numbering
 * are the validator's rules, not the parser's.
 *
 * @author Tymofii Synianskyi
 */
final class QuizBlockParser {

	public const UNEXPECTED_LINE = 'quiz.unexpected_line';

	/**
	 * @param string $body      The section body: LF line breaks, HTML comments already blanked.
	 * @param int    $body_line The file line of the body's first line.
	 *
	 * @return list<QuestionSpec>
	 */
	public function parse( string $body, int $body_line, IssueList $issues ): array {
		/** @var list<array{number: int, text: string, line: int, options: list<array{text: string, correct: bool}>, explanation: ?string}> $questions */
		$questions        = [];
		$in_stray_block   = false;
		$current_question = null;

		foreach ( explode( "\n", $body ) as $index => $text ) {
			$line = $body_line + $index;
			$text = rtrim( $text );

			if ( '' === trim( $text ) ) {
				$in_stray_block = false;
				continue;
			}

			if ( 1 === preg_match( '/^\*\*(\d+)\.[ \t]+(.*\S)\*\*$/u', $text, $matches ) ) {
				$questions[]      = [
					'number'      => (int) $matches[1],
					'text'        => trim( $matches[2] ),
					'line'        => $line,
					'options'     => [],
					'explanation' => null,
				];
				$current_question = array_key_last( $questions );
				$in_stray_block   = false;
				continue;
			}

			$open = null !== $current_question && null === $questions[ $current_question ]['explanation'];

			if ( $open && 1 === preg_match( '/^- \[( |x)\] (.*\S)$/u', $text, $matches ) ) {
				$questions[ $current_question ]['options'][] = [
					'text'    => trim( $matches[2] ),
					'correct' => 'x' === $matches[1],
				];

				$in_stray_block = false;
				continue;
			}

			if ( $open && 1 === preg_match( '/^> Пояснення:[ \t]*(\S.*)$/u', $text, $matches ) ) {
				$questions[ $current_question ]['explanation'] = trim( $matches[1] );

				$in_stray_block = false;
				continue;
			}

			if ( ! $in_stray_block ) {
				$issues->add(
					ImportIssue::error(
						self::UNEXPECTED_LINE,
						$line,
						__( 'Зайвий рядок у тесті. У тесті дозволені лише запитання «**N. текст**», варіанти «- [x] …» / «- [ ] …» під ним і один рядок «> Пояснення: …» після варіантів.', 'vl-lms' )
					)
				);
			}
			$in_stray_block = true;
		}

		return array_map(
			static fn ( array $question ): QuestionSpec => new QuestionSpec(
				$question['number'],
				$question['text'],
				$question['line'],
				$question['options'],
				$question['explanation']
			),
			$questions
		);
	}
}
