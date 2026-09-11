<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Import\Parser;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Import\Document\FrontMatterEntry;
use VL\LMS\Import\Document\FrontMatterType;
use VL\LMS\Import\Issue\ImportIssue;
use VL\LMS\Import\Issue\IssueLevel;
use VL\LMS\Import\Issue\IssueList;
use VL\LMS\Import\Parser\FrontMatterParser;

final class FrontMatterParserTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg( 1 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @dataProvider valid_values
	 *
	 * @param string|int|list<string> $expected
	 */
	public function test_parses_a_value_of_the_subset( string $text, FrontMatterType $type, string|int|array $expected ): void {
		$issues       = new IssueList();
		$front_matter = ( new FrontMatterParser() )->parse( [ $text ], 4, $issues );
		$entry        = $front_matter->get( 'key' );

		self::assertSame( [], $issues->codes() );
		self::assertInstanceOf( FrontMatterEntry::class, $entry );
		self::assertSame( $type, $entry->type );
		self::assertSame( $expected, $entry->value );
		self::assertSame( 4, $entry->line );
	}

	/**
	 * @return array<string, array{string, FrontMatterType, string|int|list<string>}>
	 */
	public static function valid_values(): array {
		return [
			'double-quoted with a colon and guillemets'    => [ 'key: "Кесарів розтин: клініка «Dovira»"', FrontMatterType::STRING, 'Кесарів розтин: клініка «Dovira»' ],
			'double-quoted with escapes and an apostrophe' => [ 'key: "Ім\'я \"лектора\" C:\\\\temp"', FrontMatterType::STRING, 'Ім\'я "лектора" C:\\temp' ],
			'double-quoted hash is not a comment'          => [ 'key: "a # b"', FrontMatterType::STRING, 'a # b' ],
			'double-quoted number stays a string'          => [ 'key: "1"', FrontMatterType::STRING, '1' ],
			'bare word'                                    => [ 'key: practitioner', FrontMatterType::STRING, 'practitioner' ],
			'bare template placeholder'                    => [ 'key: <slug-kursu>', FrontMatterType::STRING, '<slug-kursu>' ],
			'bare date-shaped placeholder'                 => [ 'key: YYYY-MM-DD', FrontMatterType::STRING, 'YYYY-MM-DD' ],
			'bare url with a colon'                        => [ 'key: https://example.com/a', FrontMatterType::STRING, 'https://example.com/a' ],
			'hash glued to a bare word'                    => [ 'key: c#sharp', FrontMatterType::STRING, 'c#sharp' ],
			'leading zero stays a string'                  => [ 'key: 007', FrontMatterType::STRING, '007' ],
			'int'                                          => [ 'key: 180', FrontMatterType::INT, 180 ],
			'zero'                                         => [ 'key: 0', FrontMatterType::INT, 0 ],
			'negative int'                                 => [ 'key: -5', FrontMatterType::INT, -5 ],
			'date'                                         => [ 'key: 2025-11-20', FrontMatterType::DATE, '2025-11-20' ],
			'leap day'                                     => [ 'key: 2024-02-29', FrontMatterType::DATE, '2024-02-29' ],
			'list'                                         => [ 'key: [cesarean, anesthesia, analgesia]', FrontMatterType::LIST, [ 'cesarean', 'anesthesia', 'analgesia' ] ],
			'list without spaces'                          => [ 'key: [a,b]', FrontMatterType::LIST, [ 'a', 'b' ] ],
			'empty list'                                   => [ 'key: []', FrontMatterType::LIST, [] ],
			'quoted string with a comment'                 => [ 'key: "Назва"   # обов\'язково', FrontMatterType::STRING, 'Назва' ],
			'bare string with a comment'                   => [ 'key: anesthesiological-support   # latin, kebab-case; = назва папки', FrontMatterType::STRING, 'anesthesiological-support' ],
			'int with a comment'                           => [ 'key: 70 # поріг', FrontMatterType::INT, 70 ],
			'date with a tab before the comment'           => [ "key: 2025-11-20\t# дата", FrontMatterType::DATE, '2025-11-20' ],
			'list with a comment'                          => [ 'key: [a, b]  # slug-и', FrontMatterType::LIST, [ 'a', 'b' ] ],
		];
	}

	/**
	 * @dataProvider invalid_lines
	 */
	public function test_reports_a_line_outside_the_subset_with_its_file_line( string $text, string $code ): void {
		$issues       = new IssueList();
		$front_matter = ( new FrontMatterParser() )->parse( [ 'title: "Курс"', $text ], 7, $issues );

		self::assertSame( [ $code ], $issues->codes() );
		self::assertSame( 8, $issues->all()[0]->line );
		self::assertSame( IssueLevel::ERROR, $issues->all()[0]->level );
		self::assertNotSame( '', $issues->all()[0]->message );
		self::assertSame( [ 'title' ], array_map( static fn ( FrontMatterEntry $entry ): string => $entry->key, $front_matter->entries ) );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function invalid_lines(): array {
		return [
			'no colon'                       => [ 'level practitioner', FrontMatterParser::INVALID_LINE ],
			'no space after the colon'       => [ 'key:value', FrontMatterParser::INVALID_LINE ],
			'space before the colon'         => [ 'key : value', FrontMatterParser::INVALID_LINE ],
			'indented key'                   => [ '  key: value', FrontMatterParser::INVALID_LINE ],
			'block list item'                => [ '  - cesarean', FrontMatterParser::INVALID_LINE ],
			'key with a dash'                => [ 'quiz-pass: 70', FrontMatterParser::INVALID_LINE ],
			'empty value'                    => [ 'key:', FrontMatterParser::INVALID_VALUE ],
			'only a comment after the key'   => [ 'key:   # немає', FrontMatterParser::INVALID_VALUE ],
			'single quotes'                  => [ "key: 'Назва'", FrontMatterParser::INVALID_VALUE ],
			'unterminated quote'             => [ 'key: "Назва', FrontMatterParser::INVALID_VALUE ],
			'unsupported escape'             => [ 'key: "a\nb"', FrontMatterParser::INVALID_VALUE ],
			'text after the closing quote'   => [ 'key: "a" b', FrontMatterParser::INVALID_VALUE ],
			'comment glued to the quote'     => [ 'key: "a"# c', FrontMatterParser::INVALID_VALUE ],
			'literal block scalar'           => [ 'key: |', FrontMatterParser::INVALID_VALUE ],
			'folded block scalar'            => [ 'key: >', FrontMatterParser::INVALID_VALUE ],
			'anchor'                         => [ 'key: &a value', FrontMatterParser::INVALID_VALUE ],
			'alias'                          => [ 'key: *a', FrontMatterParser::INVALID_VALUE ],
			'tag'                            => [ 'key: !!str 5', FrontMatterParser::INVALID_VALUE ],
			'reserved at sign'               => [ 'key: @author', FrontMatterParser::INVALID_VALUE ],
			'reserved backtick'              => [ 'key: `code`', FrontMatterParser::INVALID_VALUE ],
			'directive percent'              => [ 'key: %x', FrontMatterParser::INVALID_VALUE ],
			'flow mapping'                   => [ 'key: {a: b}', FrontMatterParser::INVALID_VALUE ],
			'closing bracket first'          => [ 'key: ]a', FrontMatterParser::INVALID_VALUE ],
			'block sequence dash'            => [ 'key: - a', FrontMatterParser::INVALID_VALUE ],
			'mapping colon inside bare text' => [ 'key: Кесарів розтин: супровід', FrontMatterParser::INVALID_VALUE ],
			'bare text ending with a colon'  => [ 'key: value:', FrontMatterParser::INVALID_VALUE ],
			'unclosed list'                  => [ 'key: [a, b', FrontMatterParser::INVALID_VALUE ],
			'empty list item'                => [ 'key: [a, , b]', FrontMatterParser::INVALID_VALUE ],
			'trailing comma in a list'       => [ 'key: [a, b,]', FrontMatterParser::INVALID_VALUE ],
			'quoted list item'               => [ 'key: ["a", b]', FrontMatterParser::INVALID_VALUE ],
			'nested list'                    => [ 'key: [a, [b]]', FrontMatterParser::INVALID_VALUE ],
			'impossible date'                => [ 'key: 2025-02-30', FrontMatterParser::INVALID_VALUE ],
			'int beyond the platform range'  => [ 'key: 99999999999999999999', FrontMatterParser::INVALID_VALUE ],
		];
	}

	public function test_skips_blank_and_comment_lines_and_keeps_entries_in_file_order(): void {
		$issues       = new IssueList();
		$front_matter = ( new FrontMatterParser() )->parse(
			[ '# коментар', '', 'title: "Курс"', "   \t", '   # відступ перед коментарем', 'version: 1' ],
			2,
			$issues
		);

		self::assertSame( [], $issues->codes() );
		self::assertSame(
			[ [ 'title', 4 ], [ 'version', 7 ] ],
			array_map( static fn ( FrontMatterEntry $entry ): array => [ $entry->key, $entry->line ], $front_matter->entries )
		);
	}

	public function test_a_repeated_key_is_reported_and_the_first_value_is_kept(): void {
		$issues       = new IssueList();
		$front_matter = ( new FrontMatterParser() )->parse( [ 'level: beginner', 'level: advanced' ], 2, $issues );

		self::assertSame( [ FrontMatterParser::DUPLICATE_KEY ], $issues->codes() );
		self::assertSame( 3, $issues->all()[0]->line );
		self::assertCount( 1, $front_matter->entries );
		self::assertSame( 'beginner', $front_matter->get( 'level' )?->value );
	}

	public function test_a_key_repeated_after_an_invalid_value_is_still_a_duplicate(): void {
		$issues = new IssueList();
		( new FrontMatterParser() )->parse( [ 'version:', 'version: 2' ], 2, $issues );

		self::assertSame( [ FrontMatterParser::INVALID_VALUE, FrontMatterParser::DUPLICATE_KEY ], $issues->codes() );
		self::assertSame(
			[ 2, 3 ],
			array_map( static fn ( ImportIssue $issue ): ?int => $issue->line, $issues->all() )
		);
	}

	public function test_get_returns_null_for_an_absent_key(): void {
		$front_matter = ( new FrontMatterParser() )->parse( [ 'title: "Курс"' ], 2, new IssueList() );

		self::assertNull( $front_matter->get( 'slug' ) );
	}

	public function test_parses_the_customer_template_frontmatter(): void {
		$lines = explode( "\n", (string) file_get_contents( dirname( __DIR__, 3 ) . '/Fixtures/Import/course-template.md' ) );

		self::assertSame( '---', $lines[0] );
		self::assertSame( '---', $lines[16] );

		$issues       = new IssueList();
		$front_matter = ( new FrontMatterParser() )->parse( array_slice( $lines, 1, 15 ), 2, $issues );

		self::assertSame( [], $issues->codes() );
		self::assertSame(
			[ 'title', 'slug', 'author', 'author_org', 'level', 'language', 'status', 'version', 'source_type', 'source_title', 'source_date', 'category', 'tags', 'duration_minutes', 'quiz_pass_percent' ],
			array_map( static fn ( FrontMatterEntry $entry ): string => $entry->key, $front_matter->entries )
		);
		self::assertSame( '<Назва курсу>', $front_matter->get( 'title' )?->value );
		self::assertSame( "<Прізвище Ім'я лектора>", $front_matter->get( 'author' )?->value );
		self::assertSame( FrontMatterType::STRING, $front_matter->get( 'slug' )?->type );
		self::assertSame( '<slug-kursu>', $front_matter->get( 'slug' )?->value );
		self::assertSame( FrontMatterType::STRING, $front_matter->get( 'source_date' )?->type );
		self::assertSame( 'YYYY-MM-DD', $front_matter->get( 'source_date' )?->value );
		self::assertSame( FrontMatterType::LIST, $front_matter->get( 'tags' )?->type );
		self::assertSame( [], $front_matter->get( 'tags' )?->value );
		self::assertSame( 1, $front_matter->get( 'version' )?->value );
		self::assertSame( 0, $front_matter->get( 'duration_minutes' )?->value );
		self::assertSame( 70, $front_matter->get( 'quiz_pass_percent' )?->value );
		self::assertSame( 16, $front_matter->get( 'quiz_pass_percent' )?->line );
	}
}
