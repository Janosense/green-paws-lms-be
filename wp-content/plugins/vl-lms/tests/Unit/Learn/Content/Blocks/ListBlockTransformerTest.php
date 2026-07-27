<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Learn\Content\Blocks;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use VL\LMS\Learn\Content\Blocks\ListBlockTransformer;
use VL\LMS\Learn\Content\ParsedBlock;

final class ListBlockTransformerTest extends TestCase {

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

	private function listItem( string $html ): ParsedBlock {
		return new ParsedBlock( 'core/list-item', [], $html, [], [] );
	}

	public function test_supports_only_core_list(): void {
		$transformer = new ListBlockTransformer();

		self::assertTrue( $transformer->supports( 'core/list' ) );
		self::assertFalse( $transformer->supports( 'core/list-item' ) );
	}

	public function test_unordered_default(): void {
		$transformer = new ListBlockTransformer();
		$block       = new ParsedBlock(
			'core/list',
			[],
			'<ul></ul>',
			[
				$this->listItem( 'one' ),
				$this->listItem( 'two' ),
			],
			[]
		);

		$result = $transformer->transform( $block );

		self::assertSame( 'list', $result['type'] );
		self::assertFalse( $result['ordered'] );
		self::assertSame( [ 'one', 'two' ], $result['items'] );
	}

	public function test_ordered_attribute_is_propagated(): void {
		$transformer = new ListBlockTransformer();
		$block       = new ParsedBlock(
			'core/list',
			[ 'ordered' => true ],
			'<ol></ol>',
			[ $this->listItem( 'a' ) ],
			[]
		);

		$result = $transformer->transform( $block );

		self::assertTrue( $result['ordered'] );
	}

	public function test_nested_list_inside_item_survives_as_inline_markup(): void {
		$transformer = new ListBlockTransformer();
		$nested      = '<ul><li>nested</li></ul>';
		$block       = new ParsedBlock(
			'core/list',
			[],
			'<ul></ul>',
			[
				$this->listItem( 'outer ' . $nested ),
			],
			[]
		);

		$result = $transformer->transform( $block );

		self::assertSame( [ 'outer <ul><li>nested</li></ul>' ], $result['items'] );
	}

	public function test_non_list_item_children_are_skipped(): void {
		$transformer = new ListBlockTransformer();
		$block       = new ParsedBlock(
			'core/list',
			[],
			'<ul></ul>',
			[
				$this->listItem( 'a' ),
				new ParsedBlock( 'core/paragraph', [], '<p>nope</p>', [], [] ),
				$this->listItem( 'b' ),
			],
			[]
		);

		self::assertSame( [ 'a', 'b' ], $transformer->transform( $block )['items'] );
	}

	public function test_kses_strips_script_inside_items(): void {
		$transformer = new ListBlockTransformer();
		$block       = new ParsedBlock(
			'core/list',
			[],
			'<ul></ul>',
			[ $this->listItem( 'safe<script>alert(1)</script>' ) ],
			[]
		);

		$result = $transformer->transform( $block );

		self::assertStringNotContainsString( '<script>', $result['items'][0] );
	}

	public function test_items_are_read_from_markup_when_there_are_no_inner_blocks(): void {
		$transformer = new ListBlockTransformer();
		$block       = new ParsedBlock(
			'core/list',
			[],
			"<ul class=\"wp-block-list\">\n<li>перший</li>\n<li>другий</li>\n</ul>",
			[],
			[]
		);

		$result = $transformer->transform( $block );

		self::assertFalse( $result['ordered'] );
		self::assertSame( [ 'перший', 'другий' ], $result['items'] );
	}

	public function test_markup_fallback_preserves_inline_markup_inside_items(): void {
		$transformer = new ListBlockTransformer();
		$block       = new ParsedBlock(
			'core/list',
			[],
			'<ul><li>a <strong>bold</strong> and <a href="https://example.test">link</a></li></ul>',
			[],
			[]
		);

		self::assertSame(
			[ 'a <strong>bold</strong> and <a href="https://example.test">link</a>' ],
			$transformer->transform( $block )['items']
		);
	}

	public function test_markup_fallback_keeps_a_nested_list_inline_within_its_item(): void {
		$transformer = new ListBlockTransformer();
		$block       = new ParsedBlock(
			'core/list',
			[],
			'<ul><li>outer<ul><li>nested</li></ul></li><li>sibling</li></ul>',
			[],
			[]
		);

		$result = $transformer->transform( $block );

		self::assertSame(
			[ 'outer<ul><li>nested</li></ul>', 'sibling' ],
			$result['items']
		);
	}

	public function test_markup_fallback_infers_ordered_from_the_root_tag(): void {
		$transformer = new ListBlockTransformer();
		$block       = new ParsedBlock(
			'core/list',
			[],
			'<ol><li>one</li></ol>',
			[],
			[]
		);

		$result = $transformer->transform( $block );

		self::assertTrue( $result['ordered'] );
		self::assertSame( [ 'one' ], $result['items'] );
	}

	public function test_markup_fallback_prefers_an_explicit_ordered_attribute(): void {
		$transformer = new ListBlockTransformer();
		$block       = new ParsedBlock(
			'core/list',
			[ 'ordered' => false ],
			'<ol><li>one</li></ol>',
			[],
			[]
		);

		self::assertFalse( $transformer->transform( $block )['ordered'] );
	}

	public function test_markup_fallback_ignores_a_nested_list_when_choosing_the_root(): void {
		$transformer = new ListBlockTransformer();
		$block       = new ParsedBlock(
			'core/list',
			[],
			'<ol><li>outer<ul><li>nested</li></ul></li></ol>',
			[],
			[]
		);

		// The first list in document order is the <ol>, not the <ul> inside it.
		self::assertTrue( $transformer->transform( $block )['ordered'] );
	}

	public function test_markup_fallback_runs_items_through_kses(): void {
		$transformer = new ListBlockTransformer();
		$block       = new ParsedBlock(
			'core/list',
			[],
			'<ul><li>safe<script>alert(1)</script></li></ul>',
			[],
			[]
		);

		self::assertStringNotContainsString(
			'<script>',
			$transformer->transform( $block )['items'][0]
		);
	}

	public function test_inner_blocks_win_over_markup(): void {
		$transformer = new ListBlockTransformer();
		$block       = new ParsedBlock(
			'core/list',
			[],
			'<ul><li>stale</li></ul>',
			[ $this->listItem( 'fresh' ) ],
			[]
		);

		self::assertSame( [ 'fresh' ], $transformer->transform( $block )['items'] );
	}

	public function test_markup_without_a_list_root_yields_no_items(): void {
		$transformer = new ListBlockTransformer();
		$block       = new ParsedBlock( 'core/list', [], '<p>not a list</p>', [], [] );

		$result = $transformer->transform( $block );

		self::assertSame( [], $result['items'] );
		self::assertFalse( $result['ordered'] );
	}

	public function test_empty_markup_yields_no_items(): void {
		$transformer = new ListBlockTransformer();

		self::assertSame( [], $transformer->transform( new ParsedBlock( 'core/list', [], '', [], [] ) )['items'] );
	}
}
