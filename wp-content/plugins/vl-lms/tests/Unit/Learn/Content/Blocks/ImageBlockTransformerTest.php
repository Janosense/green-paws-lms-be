<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Learn\Content\Blocks;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use VL\LMS\Learn\Content\Blocks\ImageBlockTransformer;
use VL\LMS\Learn\Content\ParsedBlock;

final class ImageBlockTransformerTest extends TestCase {

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
		Functions\when( 'esc_url_raw' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_supports_only_core_image(): void {
		$transformer = new ImageBlockTransformer();

		self::assertTrue( $transformer->supports( 'core/image' ) );
		self::assertFalse( $transformer->supports( 'core/file' ) );
	}

	public function test_pulls_url_alt_size_caption_from_block_attrs(): void {
		$transformer = new ImageBlockTransformer();
		$block       = new ParsedBlock(
			'core/image',
			[
				'url'    => 'https://example.com/a.png',
				'alt'    => 'Alpha',
				'width'  => 800,
				'height' => 600,
			],
			'<figure><img src="https://example.com/a.png" alt="Alpha" /><figcaption>Caption text</figcaption></figure>',
			[],
			[]
		);

		$result = $transformer->transform( $block );

		self::assertSame(
			[
				'type'    => 'image',
				'url'     => 'https://example.com/a.png',
				'alt'     => 'Alpha',
				'caption' => 'Caption text',
				'width'   => 800,
				'height'  => 600,
			],
			$result
		);
	}

	public function test_falls_back_to_parsed_img_attributes(): void {
		$transformer = new ImageBlockTransformer();
		$block       = new ParsedBlock(
			'core/image',
			[],
			'<figure><img src="https://example.com/b.jpg" alt="Beta" width="320" height="240" /></figure>',
			[],
			[]
		);

		$result = $transformer->transform( $block );

		self::assertSame( 'https://example.com/b.jpg', $result['url'] );
		self::assertSame( 'Beta', $result['alt'] );
		self::assertSame( 320, $result['width'] );
		self::assertSame( 240, $result['height'] );
		self::assertNull( $result['caption'] );
	}

	public function test_dimensions_null_when_unknown(): void {
		$transformer = new ImageBlockTransformer();
		$block       = new ParsedBlock(
			'core/image',
			[ 'url' => 'https://example.com/c.gif' ],
			'<figure><img src="https://example.com/c.gif" /></figure>',
			[],
			[]
		);

		$result = $transformer->transform( $block );

		self::assertNull( $result['width'] );
		self::assertNull( $result['height'] );
	}

	public function test_strips_script_in_caption(): void {
		$transformer = new ImageBlockTransformer();
		$block       = new ParsedBlock(
			'core/image',
			[ 'url' => 'https://example.com/d.png' ],
			'<figure><img src="https://example.com/d.png" alt="" /><figcaption>Hi<script>alert(1)</script></figcaption></figure>',
			[],
			[]
		);

		$result = $transformer->transform( $block );

		self::assertNotNull( $result['caption'] );
		self::assertStringNotContainsString( '<script>', $result['caption'] );
	}
}
