<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Learn\Video;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use VL\LMS\Learn\Video\VideoPayloadBuilder;

final class VideoPayloadBuilderTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_parse_url' )->alias(
			static fn ( string $url, int $component = -1 ) => parse_url( $url, $component )
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_empty_url_returns_null_regardless_of_provider(): void {
		$builder = new VideoPayloadBuilder();

		self::assertNull( $builder->build( 'vimeo', '' ) );
		self::assertNull( $builder->build( 'youtube', '' ) );
		self::assertNull( $builder->build( 'file', '' ) );
		self::assertNull( $builder->build( 'embed', '' ) );
	}

	public function test_unknown_provider_returns_null(): void {
		$builder = new VideoPayloadBuilder();

		self::assertNull( $builder->build( 'wistia', 'https://wistia.com/medias/abc123' ) );
		self::assertNull( $builder->build( '', 'https://example.com/video.mp4' ) );
	}

	public function test_vimeo_extracts_id_from_canonical_url(): void {
		$builder = new VideoPayloadBuilder();
		$result  = $builder->build( 'vimeo', 'https://vimeo.com/76979871' );

		self::assertSame(
			[
				'provider'    => 'vimeo',
				'url'         => 'https://vimeo.com/76979871',
				'embed_url'   => 'https://player.vimeo.com/video/76979871',
				'external_id' => '76979871',
			],
			$result
		);
	}

	public function test_vimeo_extracts_id_from_private_link_with_hash(): void {
		$builder = new VideoPayloadBuilder();
		$result  = $builder->build( 'vimeo', 'https://vimeo.com/123456789/abcdef' );

		self::assertSame( '123456789', $result['external_id'] );
		self::assertSame( 'https://player.vimeo.com/video/123456789', $result['embed_url'] );
	}

	public function test_vimeo_with_unparseable_url_returns_null_extraction_fields(): void {
		$builder = new VideoPayloadBuilder();
		$result  = $builder->build( 'vimeo', 'https://vimeo.com/channel/featured' );

		self::assertSame( 'vimeo', $result['provider'] );
		self::assertSame( 'https://vimeo.com/channel/featured', $result['url'] );
		self::assertNull( $result['embed_url'] );
		self::assertNull( $result['external_id'] );
	}

	public function test_youtube_extracts_id_from_watch_url(): void {
		$builder = new VideoPayloadBuilder();
		$result  = $builder->build( 'youtube', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ' );

		self::assertSame(
			[
				'provider'    => 'youtube',
				'url'         => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
				'embed_url'   => 'https://www.youtube.com/embed/dQw4w9WgXcQ',
				'external_id' => 'dQw4w9WgXcQ',
			],
			$result
		);
	}

	public function test_youtube_extracts_id_from_short_url(): void {
		$builder = new VideoPayloadBuilder();
		$result  = $builder->build( 'youtube', 'https://youtu.be/dQw4w9WgXcQ' );

		self::assertSame( 'dQw4w9WgXcQ', $result['external_id'] );
		self::assertSame( 'https://www.youtube.com/embed/dQw4w9WgXcQ', $result['embed_url'] );
	}

	public function test_youtube_extracts_id_from_embed_url(): void {
		$builder = new VideoPayloadBuilder();
		$result  = $builder->build( 'youtube', 'https://www.youtube.com/embed/dQw4w9WgXcQ' );

		self::assertSame( 'dQw4w9WgXcQ', $result['external_id'] );
	}

	public function test_youtube_with_no_extractable_id_returns_null_extraction_fields(): void {
		$builder = new VideoPayloadBuilder();
		$result  = $builder->build( 'youtube', 'https://www.youtube.com/' );

		self::assertSame( 'youtube', $result['provider'] );
		self::assertNull( $result['embed_url'] );
		self::assertNull( $result['external_id'] );
	}

	public function test_file_provider_returns_url_only(): void {
		$builder = new VideoPayloadBuilder();
		$result  = $builder->build( 'file', 'https://cdn.example.com/lesson.mp4' );

		self::assertSame(
			[
				'provider'    => 'file',
				'url'         => 'https://cdn.example.com/lesson.mp4',
				'embed_url'   => null,
				'external_id' => null,
			],
			$result
		);
	}

	public function test_embed_provider_returns_url_only(): void {
		$builder = new VideoPayloadBuilder();
		$result  = $builder->build( 'embed', 'https://example.com/widget.html' );

		self::assertSame( 'embed', $result['provider'] );
		self::assertNull( $result['embed_url'] );
		self::assertNull( $result['external_id'] );
	}
}
