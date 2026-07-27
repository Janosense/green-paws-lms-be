<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Learn\Content\Blocks;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use VL\LMS\Learn\Content\Blocks\HtmlFallbackBlockTransformer;
use VL\LMS\Learn\Content\ParsedBlock;

final class HtmlFallbackBlockTransformerTest extends TestCase {

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
		// Stand-in for core's `wpautop()`, mimicking only the contract this
		// transformer leans on: double-newline-separated chunks become `<p>`.
		Functions\when( 'wpautop' )->alias(
			static function ( string $html ): string {
				$chunks = preg_split( "/\n\s*\n/", trim( $html ) );
				if ( false === $chunks ) {
					return $html;
				}
				return implode(
					'',
					array_map( static fn ( string $chunk ): string => '<p>' . $chunk . '</p>', $chunks )
				);
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_supports_returns_true_for_any_block_name(): void {
		$transformer = new HtmlFallbackBlockTransformer();

		self::assertTrue( $transformer->supports( '' ) );
		self::assertTrue( $transformer->supports( 'core/paragraph' ) );
		self::assertTrue( $transformer->supports( 'core/some-future-unknown-block' ) );
		self::assertTrue( $transformer->supports( 'plugin-x/widget' ) );
	}

	public function test_transform_returns_html_shape_with_kses_filtered_inner_html(): void {
		$transformer = new HtmlFallbackBlockTransformer();
		$block       = new ParsedBlock( 'core/cover', [], '<p>Hello</p>', [], [] );

		$result = $transformer->transform( $block );

		self::assertSame(
			[
				'type' => 'html',
				'html' => '<p>Hello</p>',
			],
			$result
		);
	}

	public function test_transform_strips_dangerous_script_tags(): void {
		$transformer = new HtmlFallbackBlockTransformer();
		$block       = new ParsedBlock( '', [], '<p>Hi</p><script>alert(1)</script>', [], [] );

		$result = $transformer->transform( $block );

		self::assertStringNotContainsString( '<script>', $result['html'] );
	}

	/**
	 * Classic-editor content arrives from `parse_blocks()` as one nameless
	 * freeform block whose `inner_html` separates paragraphs with bare
	 * double newlines — `wpautop()` is what turns those back into `<p>`.
	 */
	public function test_transform_autops_nameless_freeform_content(): void {
		$transformer = new HtmlFallbackBlockTransformer();
		$block       = new ParsedBlock( '', [], "Перший рядок.\n\nДругий рядок.", [], [] );

		$result = $transformer->transform( $block );

		self::assertSame(
			[
				'type' => 'html',
				'html' => '<p>Перший рядок.</p><p>Другий рядок.</p>',
			],
			$result
		);
	}

	/**
	 * Named blocks already carry well-formed markup, so `wpautop()` must not
	 * run on them — it would wrap stray text nodes inside the block's own HTML.
	 */
	public function test_transform_leaves_named_block_html_unautopped(): void {
		$transformer = new HtmlFallbackBlockTransformer();
		$block       = new ParsedBlock( 'core/cover', [], "<div>\n\n<span>Hi</span>\n\n</div>", [], [] );

		$result = $transformer->transform( $block );

		self::assertSame( "<div>\n\n<span>Hi</span>\n\n</div>", $result['html'] );
	}
}
