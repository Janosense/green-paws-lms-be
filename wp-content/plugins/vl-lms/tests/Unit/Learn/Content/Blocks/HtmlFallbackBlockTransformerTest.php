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
		$block       = new ParsedBlock( '', [], '<p>Hello</p>', [], [] );

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
}
