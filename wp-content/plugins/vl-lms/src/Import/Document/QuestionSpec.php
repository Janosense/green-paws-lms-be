<?php

declare(strict_types=1);

namespace VL\LMS\Import\Document;

/**
 * One `**N. …**` question block of a quiz section, as written.
 *
 * Nothing is validated here: a question may have no correct option, fewer
 * than two options or a number out of sequence — those are validator rules
 * (COURSE-FORMAT.md §5). Option texts keep their inline Markdown.
 *
 * @author Tymofii Synianskyi
 */
final readonly class QuestionSpec {

	/**
	 * @param int                                      $number      The `N` of `**N. …**`.
	 * @param string                                   $text        The question text without the number and the bold markers.
	 * @param int                                      $line        1-based file line of the question.
	 * @param list<array{text: string, correct: bool}> $options     `- [x]` options have `correct = true`.
	 * @param string|null                              $explanation The `> Пояснення:` text without the prefix.
	 */
	public function __construct(
		public int $number,
		public string $text,
		public int $line,
		public array $options,
		public ?string $explanation
	) {
	}

	/**
	 * More than one `[x]` makes a multiple-choice question (COURSE-FORMAT.md §5).
	 */
	public function is_multiple_choice(): bool {
		$correct = array_filter( $this->options, static fn ( array $option ): bool => $option['correct'] );

		return count( $correct ) > 1;
	}
}
