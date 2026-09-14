<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Import\Convert;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Import\Convert\ImageRef;
use VL\LMS\Import\Convert\LessonHtmlBuilder;
use VL\LMS\Import\Convert\MarkdownToHtml;
use VL\LMS\Import\Document\CourseDocument;
use VL\LMS\Import\Document\LessonSpec;
use VL\LMS\Import\Document\SectionSpec;
use VL\LMS\Import\Parser\CourseDocumentParser;
use VL\LMS\Import\Parser\FrontMatterParser;
use VL\LMS\Import\Parser\QuizBlockParser;

final class LessonHtmlBuilderTest extends TestCase {

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

	public function test_builds_a_lesson_with_all_four_sections_to_the_expected_html(): void {
		$lesson = $this->course_with_modules()->modules[0]->lessons[0];

		$rendered = ( new LessonHtmlBuilder( new MarkdownToHtml() ) )->build( $lesson );

		self::assertSame( file_get_contents( self::FIXTURES . 'convert/lesson.html' ), $rendered->html );
		self::assertEquals(
			[ new ImageRef( 'assets/anesthesia-cesarean-basics/monitor.png', 'assets/anesthesia-cesarean-basics/monitor.png', 'Монітор під час операції', 71 ) ],
			$rendered->images
		);
	}

	public function test_a_lesson_without_materials_has_no_materials_section(): void {
		$lesson = $this->course_with_modules()->modules[0]->lessons[1];

		$html = ( new LessonHtmlBuilder( new MarkdownToHtml() ) )->build( $lesson )->html;

		self::assertStringStartsWith( "<h2>Цілі уроку</h2>\n<ul>", $html );
		self::assertStringContainsString( '<h2>Ключові висновки</h2>', $html );
		self::assertStringNotContainsString( 'Матеріали до уроку', $html );
	}

	public function test_an_image_referenced_in_two_sections_is_collected_once(): void {
		$lesson = new LessonSpec(
			1,
			1,
			'Урок',
			10,
			new SectionSpec( 'Цілі уроку', 12, '- Роздивитися ![схему](assets/a/scheme.png).', 14 ),
			new SectionSpec( 'Зміст', 16, "Текст.\n\n![Схема ще раз](assets/a/scheme.png)\n\n![Фото](assets/a/photo.png)", 18 ),
			new SectionSpec( 'Ключові висновки', 24, '- Висновок.', 26 ),
			null
		);

		$images = ( new LessonHtmlBuilder( new MarkdownToHtml() ) )->build( $lesson )->images;

		self::assertSame( [ 'assets/a/scheme.png', 'assets/a/photo.png' ], array_map( static fn ( ImageRef $image ): string => $image->path, $images ) );
		self::assertSame( [ 14, 22 ], array_map( static fn ( ImageRef $image ): int => $image->line, $images ) );
	}

	private function course_with_modules(): CourseDocument {
		$parser = new CourseDocumentParser( new FrontMatterParser(), new QuizBlockParser() );

		return $parser->parse( (string) file_get_contents( self::FIXTURES . 'course-with-modules.md' ) );
	}
}
