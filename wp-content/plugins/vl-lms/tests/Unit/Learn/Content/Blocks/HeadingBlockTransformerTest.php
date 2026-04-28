<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Learn\Content\Blocks;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use VL\LMS\Learn\Content\Blocks\HeadingBlockTransformer;
use VL\LMS\Learn\Content\ParsedBlock;

final class HeadingBlockTransformerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_strip_all_tags' )->alias(
			static fn ( string $html ): string => trim( strip_tags( $html ) )
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_supports_only_core_heading(): void {
		$transformer = new HeadingBlockTransformer();

		self::assertTrue( $transformer->supports( 'core/heading' ) );
		self::assertFalse( $transformer->supports( 'core/paragraph' ) );
	}

	public function test_transform_emits_full_shape(): void {
		$transformer = new HeadingBlockTransformer();
		$block       = new ParsedBlock(
			'core/heading',
			[
				'level'  => 3,
				'anchor' => 'section-one',
			],
			'<h3 id="section-one">Section One</h3>',
			[],
			[]
		);

		self::assertSame(
			[
				'type'   => 'heading',
				'level'  => 3,
				'text'   => 'Section One',
				'anchor' => 'section-one',
			],
			$transformer->transform( $block )
		);
	}

	public function test_default_level_is_two_when_attr_missing(): void {
		$transformer = new HeadingBlockTransformer();
		$block       = new ParsedBlock( 'core/heading', [], '<h2>Title</h2>', [], [] );

		self::assertSame( 2, $transformer->transform( $block )['level'] );
	}

	public function test_level_is_clamped_below_two(): void {
		$transformer = new HeadingBlockTransformer();
		$block       = new ParsedBlock( 'core/heading', [ 'level' => 1 ], '<h1>Big</h1>', [], [] );

		self::assertSame( 2, $transformer->transform( $block )['level'] );
	}

	public function test_level_is_clamped_above_six(): void {
		$transformer = new HeadingBlockTransformer();
		$block       = new ParsedBlock( 'core/heading', [ 'level' => 9 ], '<h6>Small</h6>', [], [] );

		self::assertSame( 6, $transformer->transform( $block )['level'] );
	}

	public function test_anchor_is_null_when_invalid_chars(): void {
		$transformer = new HeadingBlockTransformer();
		$block       = new ParsedBlock( 'core/heading', [ 'anchor' => 'has spaces!' ], '<h2>X</h2>', [], [] );

		self::assertNull( $transformer->transform( $block )['anchor'] );
	}

	public function test_text_strips_inline_tags_and_dangerous_html(): void {
		$transformer = new HeadingBlockTransformer();
		$block       = new ParsedBlock(
			'core/heading',
			[],
			'<h2><span>Hi</span><script>alert(1)</script></h2>',
			[],
			[]
		);

		$result = $transformer->transform( $block );

		self::assertSame( 'Hialert(1)', $result['text'] );
		self::assertStringNotContainsString( '<script>', $result['text'] );
	}
}
