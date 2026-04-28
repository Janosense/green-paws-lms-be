<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Learn\Content\Blocks;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use VL\LMS\Learn\Content\Blocks\TableBlockTransformer;
use VL\LMS\Learn\Content\ParsedBlock;

final class TableBlockTransformerTest extends TestCase {

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

	public function test_supports_only_core_table(): void {
		$transformer = new TableBlockTransformer();

		self::assertTrue( $transformer->supports( 'core/table' ) );
		self::assertFalse( $transformer->supports( 'core/list' ) );
	}

	public function test_well_formed_table_with_thead_tbody_tfoot(): void {
		$html = <<<'HTML'
<figure class="wp-block-table"><table>
<thead><tr><th>A</th><th>B</th></tr></thead>
<tbody><tr><td>1</td><td>2</td></tr><tr><td>3</td><td>4</td></tr></tbody>
<tfoot><tr><td>F1</td><td>F2</td></tr></tfoot>
</table></figure>
HTML;

		$transformer = new TableBlockTransformer();
		$block       = new ParsedBlock( 'core/table', [], $html, [], [] );

		$result = $transformer->transform( $block );

		self::assertSame( 'table', $result['type'] );
		self::assertTrue( $result['has_header'] );
		self::assertTrue( $result['has_footer'] );
		self::assertSame( [ [ 'A', 'B' ] ], $result['head'] );
		self::assertSame(
			[
				[ '1', '2' ],
				[ '3', '4' ],
			],
			$result['body']
		);
		self::assertSame( [ [ 'F1', 'F2' ] ], $result['foot'] );
	}

	public function test_tbody_only_table(): void {
		$html = '<table><tbody><tr><td>x</td><td>y</td></tr></tbody></table>';

		$transformer = new TableBlockTransformer();
		$result      = $transformer->transform( new ParsedBlock( 'core/table', [], $html, [], [] ) );

		self::assertFalse( $result['has_header'] );
		self::assertFalse( $result['has_footer'] );
		self::assertSame( [], $result['head'] );
		self::assertSame( [ [ 'x', 'y' ] ], $result['body'] );
		self::assertSame( [], $result['foot'] );
	}

	public function test_falls_back_to_html_shape_when_no_table_root(): void {
		$transformer = new TableBlockTransformer();
		$block       = new ParsedBlock( 'core/table', [], '<p>not a table at all</p>', [], [] );

		$result = $transformer->transform( $block );

		self::assertSame( 'html', $result['type'] );
		self::assertSame( '<p>not a table at all</p>', $result['html'] );
	}

	public function test_falls_back_to_html_shape_for_empty_inner_html(): void {
		$transformer = new TableBlockTransformer();
		$result      = $transformer->transform( new ParsedBlock( 'core/table', [], '', [], [] ) );

		self::assertSame( 'html', $result['type'] );
	}

	public function test_strips_dangerous_script_in_cells(): void {
		$html = '<table><tbody><tr><td>safe<script>alert(1)</script></td></tr></tbody></table>';

		$transformer = new TableBlockTransformer();
		$result      = $transformer->transform( new ParsedBlock( 'core/table', [], $html, [], [] ) );

		self::assertStringNotContainsString( '<script>', $result['body'][0][0] );
	}
}
