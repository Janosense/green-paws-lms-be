<?php

declare(strict_types=1);

namespace VL\LMS\Quiz\Scoring;

use VL\LMS\Domain\Quiz\QuizAnswer;
use VL\LMS\Domain\Quiz\TextMatchMode;
use VL\LMS\Support\Logger;
use WP_Post;

/**
 * Scorer for `text` questions.
 *
 * Compares the submitted text against `_vl_question_correct_text` using
 * one of three modes drawn from `_vl_question_match_mode` (default
 * `EXACT`):
 *
 * - `EXACT` — strict `===` equality.
 * - `CASE_INSENSITIVE` — lowercase + trim both sides, then compare.
 * - `REGEX` — `preg_match` against the instructor-supplied pattern.
 *
 * Regex convention: instructors store the **raw pattern** (no leading
 * delimiter). This scorer wraps the pattern with `/` delimiters and the
 * `u` flag at compile time. The PCRE backtrack limit is temporarily
 * lowered to {@see self::PCRE_BACKTRACK_LIMIT} for the duration of the
 * match — and restored afterwards regardless of outcome — so a
 * pathological pattern cannot peg the request worker. A compilation
 * error or backtrack overrun returns `0` and emits a `warning` so
 * instructor-side mistakes are observable.
 *
 * @author Tymofii Synianskyi
 */
class TextScorer {

	private const int PCRE_BACKTRACK_LIMIT = 100000;

	public function __construct( private readonly Logger $logger ) {
	}

	public function score( WP_Post $question, ?QuizAnswer $answer ): ScoringResult {
		$points = $this->read_points( (int) $question->ID );

		if ( null === $answer ) {
			return new ScoringResult( false, 0 );
		}

		$submitted = $answer->answer_data['text'] ?? null;
		if ( ! is_string( $submitted ) ) {
			return new ScoringResult( false, 0 );
		}

		$correct = $this->meta_string( (int) $question->ID, '_vl_question_correct_text' );
		if ( '' === $correct ) {
			return new ScoringResult( false, 0 );
		}

		$mode = TextMatchMode::from_meta_value(
			$this->meta_string( (int) $question->ID, '_vl_question_match_mode' )
		);

		$is_correct = match ( $mode ) {
			TextMatchMode::EXACT            => $submitted === $correct,
			TextMatchMode::CASE_INSENSITIVE => mb_strtolower( trim( $submitted ) ) === mb_strtolower( trim( $correct ) ),
			TextMatchMode::REGEX            => $this->regex_match( $correct, $submitted, (int) $question->ID ),
		};

		return new ScoringResult( $is_correct, $is_correct ? $points : 0 );
	}

	/**
	 * Bound the PCRE engine's backtrack limit before running a user-
	 * supplied pattern, then restore the prior limit. Compilation errors
	 * and backtrack overruns both surface as `false` and are mapped to
	 * "incorrect" plus a logged warning.
	 */
	private function regex_match( string $pattern, string $subject, int $question_id ): bool {
		$wrapped       = '/' . str_replace( '/', '\\/', $pattern ) . '/u';
		$prev_limit    = ini_get( 'pcre.backtrack_limit' );
		$prev_limit_str = false === $prev_limit ? '1000000' : (string) $prev_limit;
		ini_set( 'pcre.backtrack_limit', (string) self::PCRE_BACKTRACK_LIMIT );

		try {
			$result = @preg_match( $wrapped, $subject );
		} finally {
			ini_set( 'pcre.backtrack_limit', $prev_limit_str );
		}

		if ( false === $result ) {
			$this->logger->warning(
				'TextScorer: regex match failed (compilation or backtrack overrun)',
				[
					'question_id'  => $question_id,
					'preg_error'   => preg_last_error(),
					'preg_message' => function_exists( 'preg_last_error_msg' ) ? preg_last_error_msg() : '',
				]
			);
			return false;
		}

		return 1 === $result;
	}

	private function meta_string( int $post_id, string $key ): string {
		$value = get_post_meta( $post_id, $key, true );
		if ( is_string( $value ) ) {
			return $value;
		}
		return '';
	}

	private function read_points( int $question_id ): int {
		$raw = get_post_meta( $question_id, '_vl_question_points', true );
		if ( is_string( $raw ) || is_int( $raw ) ) {
			$value = (int) $raw;
			return $value > 0 ? $value : 0;
		}
		return 0;
	}
}
