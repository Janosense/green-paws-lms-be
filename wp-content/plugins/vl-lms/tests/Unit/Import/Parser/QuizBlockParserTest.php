<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Import\Parser;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Import\Issue\ImportIssue;
use VL\LMS\Import\Issue\IssueLevel;
use VL\LMS\Import\Issue\IssueList;
use VL\LMS\Import\Parser\QuizBlockParser;

final class QuizBlockParserTest extends TestCase {

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

	public function test_parses_a_single_choice_question_with_an_explanation(): void {
		$body = implode(
			"\n",
			[
				'**1. Який препарат обирають для індукції?**',
				'- [ ] Кетамін',
				'- [x] Пропофол',
				'- [ ] Діазепам',
				'> Пояснення: швидка індукція і пробудження (Урок 1.2)',
			]
		);

		$issues    = new IssueList();
		$questions = ( new QuizBlockParser() )->parse( $body, 99, $issues );

		self::assertSame( [], $issues->codes() );
		self::assertCount( 1, $questions );
		self::assertSame( 1, $questions[0]->number );
		self::assertSame( 'Який препарат обирають для індукції?', $questions[0]->text );
		self::assertSame( 99, $questions[0]->line );
		self::assertSame(
			[
				[
					'text'    => 'Кетамін',
					'correct' => false,
				],
				[
					'text'    => 'Пропофол',
					'correct' => true,
				],
				[
					'text'    => 'Діазепам',
					'correct' => false,
				],
			],
			$questions[0]->options
		);
		self::assertSame( 'швидка індукція і пробудження (Урок 1.2)', $questions[0]->explanation );
		self::assertFalse( $questions[0]->is_multiple_choice() );
	}

	public function test_more_than_one_correct_option_makes_a_multiple_choice_question(): void {
		$body = "**2. Які препарати є опіоїдами?**\n- [x] Фентаніл\n- [ ] Мелоксикам\n- [x] Бупренорфін";

		$questions = ( new QuizBlockParser() )->parse( $body, 10, new IssueList() );

		self::assertTrue( $questions[0]->is_multiple_choice() );
		self::assertNull( $questions[0]->explanation );
	}

	public function test_consecutive_questions_keep_their_numbers_and_file_lines(): void {
		$body = "**1. Перше?**\n- [x] Так\n- [ ] Ні\n\n**2. Друге?**\n- [ ] Так\n- [x] Ні\n> Пояснення: бо ні";

		$issues    = new IssueList();
		$questions = ( new QuizBlockParser() )->parse( $body, 144, $issues );

		self::assertSame( [], $issues->codes() );
		self::assertSame( [ 1, 2 ], [ $questions[0]->number, $questions[1]->number ] );
		self::assertSame( [ 144, 148 ], [ $questions[0]->line, $questions[1]->line ] );
		self::assertNull( $questions[0]->explanation );
		self::assertSame( 'бо ні', $questions[1]->explanation );
	}

	public function test_blank_lines_between_the_parts_of_a_question_are_ignored(): void {
		$body = "**1. Питання?**\n\n- [x] Так\n\n- [ ] Ні\n\n> Пояснення: так\n";

		$issues    = new IssueList();
		$questions = ( new QuizBlockParser() )->parse( $body, 1, $issues );

		self::assertSame( [], $issues->codes() );
		self::assertCount( 2, $questions[0]->options );
		self::assertSame( 'так', $questions[0]->explanation );
	}

	public function test_a_question_without_a_correct_option_still_parses(): void {
		$body = "**1. Питання?**\n- [ ] Перший\n- [ ] Другий";

		$issues    = new IssueList();
		$questions = ( new QuizBlockParser() )->parse( $body, 1, $issues );

		self::assertSame( [], $issues->codes() );
		self::assertSame( [ false, false ], array_column( $questions[0]->options, 'correct' ) );
		self::assertFalse( $questions[0]->is_multiple_choice() );
	}

	public function test_bold_inside_the_question_text_is_kept(): void {
		$questions = ( new QuizBlockParser() )->parse( "**3. Що означає **ASA III**?**\n- [x] Системне захворювання", 1, new IssueList() );

		self::assertSame( 'Що означає **ASA III**?', $questions[0]->text );
	}

	public function test_an_empty_body_gives_no_questions_and_no_issues(): void {
		$issues = new IssueList();

		self::assertSame( [], ( new QuizBlockParser() )->parse( '', 1, $issues ) );
		self::assertSame( [], $issues->codes() );
	}

	/**
	 * @dataProvider unexpected_lines
	 */
	public function test_reports_an_unexpected_line_with_its_file_line( string $body, int $offset ): void {
		$issues = new IssueList();
		( new QuizBlockParser() )->parse( $body, 40, $issues );

		self::assertSame( [ QuizBlockParser::UNEXPECTED_LINE ], $issues->codes() );
		self::assertSame( 40 + $offset, $issues->all()[0]->line );
		self::assertSame( IssueLevel::ERROR, $issues->all()[0]->level );
	}

	/**
	 * @return array<string, array{string, int}>
	 */
	public static function unexpected_lines(): array {
		return [
			'text before the first question'  => [ "Оберіть одну відповідь.\n\n**1. Питання?**\n- [x] Так", 0 ],
			'option before any question'      => [ "- [x] Так\n\n**1. Питання?**\n- [ ] Ні", 0 ],
			'question wrapped over two lines' => [ "**1. Довге\nпитання?**\n- [x] Так\n- [ ] Ні", 0 ],
			'option after the explanation'    => [ "**1. Питання?**\n- [x] Так\n> Пояснення: так\n- [ ] Ні", 3 ],
			'second explanation'              => [ "**1. Питання?**\n- [x] Так\n> Пояснення: так\n> Пояснення: ще", 3 ],
			'continued explanation'           => [ "**1. Питання?**\n- [x] Так\n> Пояснення: так\n> і ще рядок", 3 ],
			'empty explanation'               => [ "**1. Питання?**\n- [x] Так\n> Пояснення:", 2 ],
			'uppercase X marker'              => [ "**1. Питання?**\n- [X] Так\n- [ ] Ні", 1 ],
			'asterisk bullet'                 => [ "**1. Питання?**\n* [x] Так", 1 ],
			'option without text'             => [ "**1. Питання?**\n- [x]\n- [ ] Ні", 1 ],
			'lettered option'                 => [ "**1. Питання?**\n- a) Так", 1 ],
			'answers block'                   => [ "**1. Питання?**\n- [x] Так\n\nВідповіді: 1 — Так", 3 ],
			'sub-heading'                     => [ "**1. Питання?**\n- [x] Так\n\n#### Підзаголовок", 3 ],
		];
	}

	public function test_a_block_of_stray_lines_is_reported_once_per_block(): void {
		$body = "Вступ, рядок 1\nрядок 2\nрядок 3\n\n**1. Питання?**\n- [x] Так\n\nще текст";

		$issues = new IssueList();
		( new QuizBlockParser() )->parse( $body, 10, $issues );

		self::assertSame(
			[ 10, 17 ],
			array_map( static fn ( ImportIssue $issue ): ?int => $issue->line, $issues->all() )
		);
	}

	public function test_a_valid_line_ends_a_stray_block(): void {
		$body = "**1. Питання?**\nзайвий рядок\n- [x] Так\nще зайвий";

		$issues    = new IssueList();
		$questions = ( new QuizBlockParser() )->parse( $body, 10, $issues );

		self::assertSame(
			[ 11, 13 ],
			array_map( static fn ( ImportIssue $issue ): ?int => $issue->line, $issues->all() )
		);
		self::assertCount( 1, $questions[0]->options );
	}
}
