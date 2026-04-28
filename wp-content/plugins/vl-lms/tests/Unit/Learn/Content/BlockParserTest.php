<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Learn\Content;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use VL\LMS\Learn\Content\BlockParser;
use VL\LMS\Learn\Content\ParsedBlock;

final class BlockParserTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_empty_string_returns_empty_array(): void {
		Functions\when( 'parse_blocks' )->justReturn( [] );
		$parser = new BlockParser();

		self::assertSame( [], $parser->parse( '' ) );
	}

	public function test_whitespace_only_input_returns_empty_array(): void {
		Functions\when( 'parse_blocks' )->justReturn( [] );
		$parser = new BlockParser();

		self::assertSame( [], $parser->parse( "   \n\t  " ) );
	}

	public function test_filters_out_empty_freeform_blocks(): void {
		Functions\when( 'parse_blocks' )->justReturn(
			[
				[
					'blockName'    => null,
					'attrs'        => [],
					'innerHTML'    => "\n\n",
					'innerBlocks'  => [],
					'innerContent' => [ "\n\n" ],
				],
				[
					'blockName'    => 'core/paragraph',
					'attrs'        => [],
					'innerHTML'    => '<p>Hello</p>',
					'innerBlocks'  => [],
					'innerContent' => [ '<p>Hello</p>' ],
				],
				[
					'blockName'    => null,
					'attrs'        => [],
					'innerHTML'    => "  \n",
					'innerBlocks'  => [],
					'innerContent' => [ "  \n" ],
				],
			]
		);

		$parser = new BlockParser();
		$blocks = $parser->parse( 'irrelevant' );

		self::assertCount( 1, $blocks );
		self::assertSame( 'core/paragraph', $blocks[0]->name );
	}

	public function test_classic_editor_content_surfaces_as_single_nameless_block(): void {
		// `parse_blocks()` emits one freeform `blockName=null` block when
		// content predates the block editor. A nameless block with non-empty
		// inner_html survives the filter so the HTML-fallback transformer
		// can render it.
		Functions\when( 'parse_blocks' )->justReturn(
			[
				[
					'blockName'    => null,
					'attrs'        => [],
					'innerHTML'    => '<p>Classic editor content.</p>',
					'innerBlocks'  => [],
					'innerContent' => [ '<p>Classic editor content.</p>' ],
				],
			]
		);

		$parser = new BlockParser();
		$blocks = $parser->parse( 'irrelevant' );

		self::assertCount( 1, $blocks );
		self::assertSame( '', $blocks[0]->name );
		self::assertSame( '<p>Classic editor content.</p>', $blocks[0]->inner_html );
	}

	public function test_inner_blocks_are_recursively_wrapped(): void {
		Functions\when( 'parse_blocks' )->justReturn(
			[
				[
					'blockName'   => 'core/list',
					'attrs'       => [ 'ordered' => false ],
					'innerHTML'   => '<ul></ul>',
					'innerBlocks' => [
						[
							'blockName'    => 'core/list-item',
							'attrs'        => [],
							'innerHTML'    => '<li>One</li>',
							'innerBlocks'  => [],
							'innerContent' => [ '<li>One</li>' ],
						],
						[
							'blockName'    => 'core/list-item',
							'attrs'        => [],
							'innerHTML'    => '<li>Two</li>',
							'innerBlocks'  => [],
							'innerContent' => [ '<li>Two</li>' ],
						],
					],
					'innerContent' => [ '<ul>', null, null, '</ul>' ],
				],
			]
		);

		$parser = new BlockParser();
		$blocks = $parser->parse( 'irrelevant' );

		self::assertCount( 1, $blocks );
		self::assertSame( 'core/list', $blocks[0]->name );
		self::assertCount( 2, $blocks[0]->inner_blocks );
		self::assertContainsOnlyInstancesOf( ParsedBlock::class, $blocks[0]->inner_blocks );
		self::assertSame( 'core/list-item', $blocks[0]->inner_blocks[0]->name );
		self::assertSame( '<li>Two</li>', $blocks[0]->inner_blocks[1]->inner_html );
	}

	public function test_inner_content_preserves_string_and_null_markers(): void {
		Functions\when( 'parse_blocks' )->justReturn(
			[
				[
					'blockName'    => 'core/columns',
					'attrs'        => [],
					'innerHTML'    => '<div></div>',
					'innerBlocks'  => [],
					'innerContent' => [ '<div>', null, '</div>' ],
				],
			]
		);

		$parser = new BlockParser();
		$blocks = $parser->parse( 'irrelevant' );

		self::assertSame( [ '<div>', null, '</div>' ], $blocks[0]->inner_content );
	}

	public function test_non_array_parse_blocks_result_returns_empty_list(): void {
		Functions\when( 'parse_blocks' )->justReturn( null );
		$parser = new BlockParser();

		self::assertSame( [], $parser->parse( 'irrelevant' ) );
	}
}
