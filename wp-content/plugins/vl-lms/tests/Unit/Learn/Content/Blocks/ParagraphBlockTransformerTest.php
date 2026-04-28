<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Learn\Content\Blocks;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use VL\LMS\Learn\Content\Blocks\ParagraphBlockTransformer;
use VL\LMS\Learn\Content\ParsedBlock;

final class ParagraphBlockTransformerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_kses_post' )->alias(
			static fn ( string $html ): string => str_replace(
				// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Test-only mock for `wp_kses_post`; not real script enqueueing.
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

	public function test_supports_only_core_paragraph(): void {
		$transformer = new ParagraphBlockTransformer();

		self::assertTrue( $transformer->supports( 'core/paragraph' ) );
		self::assertFalse( $transformer->supports( 'core/heading' ) );
		self::assertFalse( $transformer->supports( '' ) );
	}

	public function test_transform_emits_paragraph_shape(): void {
		$transformer = new ParagraphBlockTransformer();
		$block       = new ParsedBlock( 'core/paragraph', [], '<p>Hello</p>', [], [] );

		self::assertSame(
			[
				'type' => 'paragraph',
				'html' => '<p>Hello</p>',
			],
			$transformer->transform( $block )
		);
	}

	public function test_transform_strips_script_via_kses(): void {
		$transformer = new ParagraphBlockTransformer();
		$block       = new ParsedBlock( 'core/paragraph', [], '<p>Hi</p><script>alert(1)</script>', [], [] );

		$result = $transformer->transform( $block );

		self::assertStringNotContainsString( '<script>', $result['html'] );
	}
}
