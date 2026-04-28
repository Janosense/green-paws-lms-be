<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Learn\Content\Blocks;

use PHPUnit\Framework\TestCase;
use VL\LMS\Learn\Content\Blocks\SeparatorBlockTransformer;
use VL\LMS\Learn\Content\ParsedBlock;

final class SeparatorBlockTransformerTest extends TestCase {

	public function test_supports_only_core_separator(): void {
		$transformer = new SeparatorBlockTransformer();

		self::assertTrue( $transformer->supports( 'core/separator' ) );
		self::assertFalse( $transformer->supports( 'core/spacer' ) );
	}

	public function test_transform_emits_separator_shape(): void {
		$transformer = new SeparatorBlockTransformer();
		$block       = new ParsedBlock( 'core/separator', [], '<hr />', [], [] );

		self::assertSame( [ 'type' => 'separator' ], $transformer->transform( $block ) );
	}

	public function test_transform_ignores_inner_html_so_no_html_can_leak(): void {
		$transformer = new SeparatorBlockTransformer();
		$block       = new ParsedBlock( 'core/separator', [], '<script>alert(1)</script>', [], [] );

		$result = $transformer->transform( $block );

		self::assertSame( [ 'type' => 'separator' ], $result );
		self::assertArrayNotHasKey( 'html', $result );
	}
}
