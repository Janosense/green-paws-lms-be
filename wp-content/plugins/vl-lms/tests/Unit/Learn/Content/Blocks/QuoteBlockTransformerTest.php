<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Learn\Content\Blocks;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use VL\LMS\Learn\Content\Blocks\QuoteBlockTransformer;
use VL\LMS\Learn\Content\ParsedBlock;

final class QuoteBlockTransformerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_kses_post' )->alias(
			static fn ( string $html ): string => str_replace(
				[ '<script>', '</script>' ],
				[ '', '' ],
				$html
			)
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_supports_only_core_quote(): void {
		$transformer = new QuoteBlockTransformer();

		self::assertTrue( $transformer->supports( 'core/quote' ) );
		self::assertFalse( $transformer->supports( 'core/paragraph' ) );
	}

	public function test_emits_quote_shape_with_attribute_citation(): void {
		$transformer = new QuoteBlockTransformer();
		$block       = new ParsedBlock(
			'core/quote',
			[ 'citation' => 'Aristotle' ],
			'<blockquote><p>The whole is greater than the sum of its parts.</p></blockquote>',
			[],
			[]
		);

		$result = $transformer->transform( $block );

		self::assertSame( 'quote', $result['type'] );
		self::assertSame( 'Aristotle', $result['citation'] );
		self::assertStringContainsString( '<blockquote>', $result['html'] );
	}

	public function test_falls_back_to_cite_tag_when_attr_missing(): void {
		$transformer = new QuoteBlockTransformer();
		$block       = new ParsedBlock(
			'core/quote',
			[],
			'<blockquote><p>x</p><cite>Plato</cite></blockquote>',
			[],
			[]
		);

		self::assertSame( 'Plato', $transformer->transform( $block )['citation'] );
	}

	public function test_citation_null_when_absent(): void {
		$transformer = new QuoteBlockTransformer();
		$block       = new ParsedBlock(
			'core/quote',
			[],
			'<blockquote><p>x</p></blockquote>',
			[],
			[]
		);

		self::assertNull( $transformer->transform( $block )['citation'] );
	}

	public function test_strips_dangerous_script(): void {
		$transformer = new QuoteBlockTransformer();
		$block       = new ParsedBlock(
			'core/quote',
			[],
			'<blockquote><p>x</p><script>alert(1)</script></blockquote>',
			[],
			[]
		);

		$result = $transformer->transform( $block );

		self::assertStringNotContainsString( '<script>', $result['html'] );
	}
}
