<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Import\Convert;

use PHPUnit\Framework\TestCase;
use VL\LMS\Import\Convert\ImageRef;
use VL\LMS\Import\Convert\MarkdownToHtml;

final class MarkdownToHtmlTest extends TestCase {

	private const FIXTURES = __DIR__ . '/../../../Fixtures/Import/convert/';

	public function test_renders_the_body_fixture_to_the_expected_html(): void {
		$rendered = ( new MarkdownToHtml() )->render( $this->fixture( 'body.md' ), 1 );

		self::assertSame( file_get_contents( self::FIXTURES . 'body.html' ), $rendered->html );
	}

	public function test_a_paragraph_written_over_two_lines_renders_on_one_line(): void {
		$html = ( new MarkdownToHtml() )->render( "Перший рядок\nдругий рядок.", 1 )->html;

		self::assertSame( "<p>Перший рядок другий рядок.</p>\n", $html );
	}

	/**
	 * @dataProvider heading_levels
	 */
	public function test_headings_render_below_the_section_heading( string $markdown, string $expected ): void {
		self::assertSame( $expected, ( new MarkdownToHtml() )->render( $markdown, 1 )->html );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function heading_levels(): array {
		return [
			'####'            => [ '#### Підтема', "<h3>Підтема</h3>\n" ],
			'#####'           => [ '##### Підтема', "<h4>Підтема</h4>\n" ],
			'######'          => [ '###### Підтема', "<h5>Підтема</h5>\n" ],
			'setext `===`'    => [ "Підтема\n===", "<h3>Підтема</h3>\n" ],
			'setext `-` line' => [ "Підтема\n-", "<h3>Підтема</h3>\n" ],
		];
	}

	public function test_collects_an_assets_image_with_its_file_line(): void {
		$images = ( new MarkdownToHtml() )->render( $this->fixture( 'body.md' ), 10 )->images;

		self::assertEquals(
			[ new ImageRef( 'assets/anesthesia/monitor.png', 'assets/anesthesia/monitor.png', 'Монітор під час операції', 29 ) ],
			$images
		);
	}

	public function test_an_image_path_is_decoded_and_collected_once(): void {
		$markdown = "Схема ![Фото *пса*](assets/a/%D1%84%20b.png)\n\n![повтор](<assets/a/ф b.png>) ![зовнішнє](https://example.com/a.png) ![інше](images/a.png)";

		$rendered = ( new MarkdownToHtml() )->render( $markdown, 40 );

		self::assertEquals( [ new ImageRef( 'assets/a/%D1%84%20b.png', 'assets/a/ф b.png', 'Фото пса', 40 ) ], $rendered->images );
		self::assertStringContainsString( '<img src="https://example.com/a.png" alt="зовнішнє" />', $rendered->html );
	}

	public function test_an_image_in_a_later_block_reports_that_block_line(): void {
		$images = ( new MarkdownToHtml() )->render( "Абзац.\n\n| Схема |\n|---|\n| ![a](assets/a/b.png) |", 7 )->images;

		self::assertSame( 9, $images[0]->line );
	}

	public function test_reports_raw_html_at_its_file_lines_and_strips_it(): void {
		$markdown         = "Текст <b>жирний</b>\nі далі\n\n<div>\nблок\n</div>\n\nКінець <span class=\"x\">тексту</span>.";
		$markdown_to_html = new MarkdownToHtml();

		self::assertSame( [ 5, 8, 12 ], $markdown_to_html->raw_html_lines( $markdown, 5 ) );

		$html = $markdown_to_html->render( $markdown, 5 )->html;
		self::assertStringNotContainsString( '<b>', $html );
		self::assertStringNotContainsString( '<div>', $html );
		self::assertStringNotContainsString( '<span', $html );
	}

	/**
	 * @dataProvider not_raw_html
	 */
	public function test_text_commonmark_does_not_read_as_html_is_not_reported( string $markdown ): void {
		self::assertSame( [], ( new MarkdownToHtml() )->raw_html_lines( $markdown, 1 ) );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function not_raw_html(): array {
		return [
			'template placeholder'  => [ '<Назва курсу> і <1–3 абзаци> і <…>' ],
			'autolink'              => [ 'Див. <https://wsava.org/global-guidelines/>.' ],
			'email autolink'        => [ 'Пишіть на <vet@clinic.ua>.' ],
			'escaped angle bracket' => [ 'Тег \<b> не працює.' ],
			'code span'             => [ 'Тег `<br>` не працює.' ],
			'comparison'            => [ 'Якщо ASA < 3 і доза <5 мг/кг.' ],
		];
	}

	public function test_an_unsafe_link_loses_its_target(): void {
		$html = ( new MarkdownToHtml() )->render( '[посилання](javascript:alert(1))', 1 )->html;

		self::assertSame( "<p><a>посилання</a></p>\n", $html );
	}

	private function fixture( string $name ): string {
		return rtrim( (string) file_get_contents( self::FIXTURES . $name ), "\n" );
	}
}
