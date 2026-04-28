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
}
