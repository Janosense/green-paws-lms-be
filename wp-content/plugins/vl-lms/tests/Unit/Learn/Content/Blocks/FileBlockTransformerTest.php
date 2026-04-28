<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Learn\Content\Blocks;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use VL\LMS\Learn\Content\Blocks\FileBlockTransformer;
use VL\LMS\Learn\Content\ParsedBlock;

final class FileBlockTransformerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'esc_url_raw' )->returnArg();
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

	public function test_supports_only_core_file(): void {
		$transformer = new FileBlockTransformer();

		self::assertTrue( $transformer->supports( 'core/file' ) );
		self::assertFalse( $transformer->supports( 'core/image' ) );
	}

	public function test_resolves_size_via_wp_get_attachment_metadata_when_id_present(): void {
		Functions\when( 'wp_get_attachment_metadata' )->alias(
			static fn ( int $id ): array => 99 === $id ? [ 'filesize' => 102400 ] : []
		);

		$transformer = new FileBlockTransformer();
		$block       = new ParsedBlock(
			'core/file',
			[
				'id'       => 99,
				'href'     => 'https://example.com/notes.pdf',
				'fileName' => 'notes.pdf',
			],
			'',
			[],
			[]
		);

		self::assertSame(
			[
				'type' => 'file',
				'url'  => 'https://example.com/notes.pdf',
				'name' => 'notes.pdf',
				'size' => 102400,
			],
			$transformer->transform( $block )
		);
	}

	public function test_size_is_null_when_id_missing(): void {
		Functions\when( 'wp_get_attachment_metadata' )->justReturn( false );

		$transformer = new FileBlockTransformer();
		$block       = new ParsedBlock(
			'core/file',
			[
				'href'     => 'https://example.com/x.pdf',
				'fileName' => 'x.pdf',
			],
			'',
			[],
			[]
		);

		self::assertNull( $transformer->transform( $block )['size'] );
	}

	public function test_size_is_null_when_metadata_lacks_filesize(): void {
		Functions\when( 'wp_get_attachment_metadata' )->justReturn( [ 'width' => 100 ] );

		$transformer = new FileBlockTransformer();
		$block       = new ParsedBlock(
			'core/file',
			[
				'id'       => 5,
				'href'     => 'https://example.com/y.pdf',
				'fileName' => 'y.pdf',
			],
			'',
			[],
			[]
		);

		self::assertNull( $transformer->transform( $block )['size'] );
	}

	public function test_strips_script_in_name(): void {
		$transformer = new FileBlockTransformer();
		$block       = new ParsedBlock(
			'core/file',
			[
				'href'     => 'https://example.com/z.pdf',
				'fileName' => 'safe<script>alert(1)</script>.pdf',
			],
			'',
			[],
			[]
		);

		self::assertStringNotContainsString( '<script>', $transformer->transform( $block )['name'] );
	}
}
