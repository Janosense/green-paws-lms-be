<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Import\Convert;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Import\Convert\CourseHtmlBuilder;
use VL\LMS\Import\Convert\MarkdownToHtml;
use VL\LMS\Import\Document\CourseDocument;
use VL\LMS\Import\Parser\CourseDocumentParser;
use VL\LMS\Import\Parser\FrontMatterParser;
use VL\LMS\Import\Parser\QuizBlockParser;

final class CourseHtmlBuilderTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const FIXTURES = __DIR__ . '/../../../Fixtures/Import/';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'esc_html' )->alias( static fn ( string $text ): string => htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_builds_about_goals_and_literature_to_the_expected_html(): void {
		$rendered = ( new CourseHtmlBuilder( new MarkdownToHtml() ) )->build( $this->parse( 'course-with-modules.md' ) );

		self::assertSame( file_get_contents( self::FIXTURES . 'convert/course.html' ), $rendered->html );
		self::assertSame( [], $rendered->images );
	}

	public function test_the_description_opens_with_the_about_text_without_a_heading(): void {
		$html = ( new CourseHtmlBuilder( new MarkdownToHtml() ) )->build( $this->parse( 'course-with-modules.md' ) )->html;

		self::assertStringStartsWith( '<p>Курс побудовано', $html );
		self::assertStringNotContainsString( 'Про курс', $html );
	}

	public function test_a_course_without_literature_ends_with_its_goals(): void {
		$html = ( new CourseHtmlBuilder( new MarkdownToHtml() ) )->build( $this->parse( 'course-flat.md' ) )->html;

		self::assertStringContainsString( "<h2>Мета курсу</h2>\n", $html );
		self::assertStringEndsWith( "<li>складати план знеболення.</li>\n</ul>\n", $html );
		self::assertStringNotContainsString( 'Література', $html );
	}

	private function parse( string $fixture ): CourseDocument {
		$parser = new CourseDocumentParser( new FrontMatterParser(), new QuizBlockParser() );

		return $parser->parse( (string) file_get_contents( self::FIXTURES . $fixture ) );
	}
}
