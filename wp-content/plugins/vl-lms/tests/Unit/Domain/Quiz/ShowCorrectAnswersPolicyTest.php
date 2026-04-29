<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Domain\Quiz;

use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Quiz\ShowCorrectAnswersPolicy;

final class ShowCorrectAnswersPolicyTest extends TestCase {

	public function test_cases_match_database_values(): void {
		self::assertSame( 'never', ShowCorrectAnswersPolicy::NEVER->value );
		self::assertSame( 'after_submit', ShowCorrectAnswersPolicy::AFTER_SUBMIT->value );
		self::assertSame( 'after_pass', ShowCorrectAnswersPolicy::AFTER_PASS->value );
	}

	public function test_from_meta_value_round_trips_known_values(): void {
		self::assertSame( ShowCorrectAnswersPolicy::NEVER, ShowCorrectAnswersPolicy::from_meta_value( 'never' ) );
		self::assertSame( ShowCorrectAnswersPolicy::AFTER_SUBMIT, ShowCorrectAnswersPolicy::from_meta_value( 'after_submit' ) );
		self::assertSame( ShowCorrectAnswersPolicy::AFTER_PASS, ShowCorrectAnswersPolicy::from_meta_value( 'after_pass' ) );
	}

	public function test_from_meta_value_defaults_to_after_submit_on_unknown(): void {
		self::assertSame( ShowCorrectAnswersPolicy::AFTER_SUBMIT, ShowCorrectAnswersPolicy::from_meta_value( 'always' ) );
		self::assertSame( ShowCorrectAnswersPolicy::AFTER_SUBMIT, ShowCorrectAnswersPolicy::from_meta_value( '' ) );
	}
}
