<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Domain\Quiz;

use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Quiz\QuestionType;

final class QuestionTypeTest extends TestCase {

	public function test_cases_match_database_values(): void {
		self::assertSame( 'single_choice', QuestionType::SINGLE_CHOICE->value );
		self::assertSame( 'multiple_choice', QuestionType::MULTIPLE_CHOICE->value );
		self::assertSame( 'true_false', QuestionType::TRUE_FALSE->value );
		self::assertSame( 'text', QuestionType::TEXT->value );
	}

	public function test_from_meta_value_round_trips_known_values(): void {
		self::assertSame( QuestionType::SINGLE_CHOICE, QuestionType::from_meta_value( 'single_choice' ) );
		self::assertSame( QuestionType::MULTIPLE_CHOICE, QuestionType::from_meta_value( 'multiple_choice' ) );
		self::assertSame( QuestionType::TRUE_FALSE, QuestionType::from_meta_value( 'true_false' ) );
		self::assertSame( QuestionType::TEXT, QuestionType::from_meta_value( 'text' ) );
	}

	public function test_from_meta_value_defaults_to_single_choice_on_unknown(): void {
		self::assertSame( QuestionType::SINGLE_CHOICE, QuestionType::from_meta_value( 'unknown' ) );
		self::assertSame( QuestionType::SINGLE_CHOICE, QuestionType::from_meta_value( '' ) );
	}
}
