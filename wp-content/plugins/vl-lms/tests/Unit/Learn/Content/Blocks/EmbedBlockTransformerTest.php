<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Learn\Content\Blocks;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use VL\LMS\Learn\Content\Blocks\EmbedBlockTransformer;
use VL\LMS\Learn\Content\ParsedBlock;
use VL\LMS\Learn\Video\VideoPayloadBuilder;

final class EmbedBlockTransformerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_parse_url' )->alias(
			static fn ( string $url, int $component = -1 ) => parse_url( $url, $component )
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_supports_only_core_embed(): void {
		$transformer = new EmbedBlockTransformer( new VideoPayloadBuilder() );

		self::assertTrue( $transformer->supports( 'core/embed' ) );
		self::assertFalse( $transformer->supports( 'core/embed-vimeo' ) );
	}

	public function test_vimeo_url_yields_vimeo_provider_and_embed_url(): void {
		$transformer = new EmbedBlockTransformer( new VideoPayloadBuilder() );
		$block       = new ParsedBlock(
			'core/embed',
			[ 'url' => 'https://vimeo.com/76979871' ],
			'',
			[],
			[]
		);

		self::assertSame(
			[
				'type'      => 'embed',
				'provider'  => 'vimeo',
				'url'       => 'https://vimeo.com/76979871',
				'embed_url' => 'https://player.vimeo.com/video/76979871',
			],
			$transformer->transform( $block )
		);
	}

	public function test_youtube_url_yields_youtube_provider_and_embed_url(): void {
		$transformer = new EmbedBlockTransformer( new VideoPayloadBuilder() );
		$block       = new ParsedBlock(
			'core/embed',
			[ 'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ' ],
			'',
			[],
			[]
		);

		$result = $transformer->transform( $block );

		self::assertSame( 'youtube', $result['provider'] );
		self::assertSame( 'https://www.youtube.com/embed/dQw4w9WgXcQ', $result['embed_url'] );
	}

	public function test_other_provider_for_unknown_host(): void {
		$transformer = new EmbedBlockTransformer( new VideoPayloadBuilder() );
		$block       = new ParsedBlock(
			'core/embed',
			[ 'url' => 'https://twitter.com/anthropicai/status/1' ],
			'',
			[],
			[]
		);

		$result = $transformer->transform( $block );

		self::assertSame( 'other', $result['provider'] );
		self::assertNull( $result['embed_url'] );
	}

	public function test_missing_url_attribute_yields_other_provider(): void {
		$transformer = new EmbedBlockTransformer( new VideoPayloadBuilder() );
		$block       = new ParsedBlock( 'core/embed', [], '', [], [] );

		$result = $transformer->transform( $block );

		self::assertSame( 'other', $result['provider'] );
		self::assertNull( $result['embed_url'] );
	}

	public function test_player_vimeo_subdomain_is_recognized(): void {
		$transformer = new EmbedBlockTransformer( new VideoPayloadBuilder() );
		$block       = new ParsedBlock(
			'core/embed',
			[ 'url' => 'https://player.vimeo.com/video/76979871' ],
			'',
			[],
			[]
		);

		$result = $transformer->transform( $block );

		self::assertSame( 'vimeo', $result['provider'] );
		self::assertSame( 'https://player.vimeo.com/video/76979871', $result['embed_url'] );
	}
}
