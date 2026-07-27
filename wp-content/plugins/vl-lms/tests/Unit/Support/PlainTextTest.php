<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Support;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Support\PlainText;

final class PlainTextTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Stand-in for WP core's tag stripper: enough fidelity for these
		// assertions (drop tags, leave entities alone).
		Functions\when( 'wp_strip_all_tags' )->alias(
			static fn ( string $text ): string => (string) preg_replace( '/<[^>]*>/', '', $text )
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * The reported regression: `wptexturize()` turns a typed `'` into
	 * `&#8217;`, which a JSON string field hands to the frontend verbatim.
	 */
	public function test_decodes_the_typographic_apostrophe_entity(): void {
		self::assertSame(
			'Це триває п’ять днів',
			PlainText::from_html( 'Це триває п&#8217;ять днів' )
		);
	}

	public function test_decodes_curly_quote_and_dash_entities(): void {
		self::assertSame(
			'Курс “Кардіологія” — базовий',
			PlainText::from_html( 'Курс &#8220;Кардіологія&#8221; &#8212; базовий' )
		);
	}

	public function test_decodes_named_entities_including_quotes_and_ampersands(): void {
		self::assertSame(
			'Cats & Dogs "basics"',
			PlainText::from_html( 'Cats &amp; Dogs &quot;basics&quot;' )
		);
	}

	public function test_strips_tags_before_decoding(): void {
		self::assertSame(
			'Вступний блок',
			PlainText::from_html( '<p><strong>Вступний</strong> блок</p>' )
		);
	}

	/**
	 * Decoding runs last on purpose: an escaped `&lt;script&gt;` in the
	 * source must survive as literal text rather than being resurrected
	 * into a tag that the stripper then eats.
	 */
	public function test_escaped_markup_survives_as_literal_text(): void {
		self::assertSame(
			'<script>alert(1)</script>',
			PlainText::from_html( '&lt;script&gt;alert(1)&lt;/script&gt;' )
		);
	}

	public function test_trims_surrounding_whitespace(): void {
		self::assertSame( 'Опис курсу', PlainText::from_html( "  \n Опис курсу \t " ) );
	}

	public function test_empty_string_stays_empty(): void {
		self::assertSame( '', PlainText::from_html( '' ) );
	}
}
