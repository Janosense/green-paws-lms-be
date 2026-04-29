<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Domain\Quiz;

use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Quiz\TextMatchMode;

final class TextMatchModeTest extends TestCase {

	public function test_cases_match_database_values(): void {
		self::assertSame( 'exact', TextMatchMode::EXACT->value );
		self::assertSame( 'case_insensitive', TextMatchMode::CASE_INSENSITIVE->value );
		self::assertSame( 'regex', TextMatchMode::REGEX->value );
	}

	public function test_from_meta_value_round_trips_known_values(): void {
		self::assertSame( TextMatchMode::EXACT, TextMatchMode::from_meta_value( 'exact' ) );
		self::assertSame( TextMatchMode::CASE_INSENSITIVE, TextMatchMode::from_meta_value( 'case_insensitive' ) );
		self::assertSame( TextMatchMode::REGEX, TextMatchMode::from_meta_value( 'regex' ) );
	}

	public function test_from_meta_value_defaults_to_exact_on_unknown(): void {
		self::assertSame( TextMatchMode::EXACT, TextMatchMode::from_meta_value( 'fuzzy' ) );
		self::assertSame( TextMatchMode::EXACT, TextMatchMode::from_meta_value( '' ) );
	}
}
