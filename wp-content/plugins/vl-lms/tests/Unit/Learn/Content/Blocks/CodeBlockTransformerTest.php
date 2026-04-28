<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Learn\Content\Blocks;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use VL\LMS\Learn\Content\Blocks\CodeBlockTransformer;
use VL\LMS\Learn\Content\ParsedBlock;

final class CodeBlockTransformerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_strip_all_tags' )->alias(
			static fn ( string $html ): string => strip_tags( $html )
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_supports_only_core_code(): void {
		$transformer = new CodeBlockTransformer();

		self::assertTrue( $transformer->supports( 'core/code' ) );
		self::assertFalse( $transformer->supports( 'core/preformatted' ) );
	}

	public function test_extracts_code_text_and_php_language(): void {
		$transformer = new CodeBlockTransformer();
		$block       = new ParsedBlock(
			'core/code',
			[ 'className' => 'language-php' ],
			'<pre><code>echo \'hi\';</code></pre>',
			[],
			[]
		);

		self::assertSame(
			[
				'type'     => 'code',
				'code'     => 'echo \'hi\';',
				'language' => 'php',
			],
			$transformer->transform( $block )
		);
	}

	public function test_no_class_means_null_language(): void {
		$transformer = new CodeBlockTransformer();
		$block       = new ParsedBlock(
			'core/code',
			[],
			'<pre><code>plain text</code></pre>',
			[],
			[]
		);

		$result = $transformer->transform( $block );

		self::assertSame( 'plain text', $result['code'] );
		self::assertNull( $result['language'] );
	}

	public function test_decodes_html_entities_so_clients_get_raw_source(): void {
		$transformer = new CodeBlockTransformer();
		$block       = new ParsedBlock(
			'core/code',
			[],
			'<pre><code>if (a &lt; b) { return &amp;a; }</code></pre>',
			[],
			[]
		);

		self::assertSame( 'if (a < b) { return &a; }', $transformer->transform( $block )['code'] );
	}

	public function test_strips_dangerous_script_via_strip_all_tags(): void {
		$transformer = new CodeBlockTransformer();
		$block       = new ParsedBlock(
			'core/code',
			[],
			'<pre><code>safe<script>alert(1)</script></code></pre>',
			[],
			[]
		);

		$result = $transformer->transform( $block );

		self::assertStringNotContainsString( '<script>', $result['code'] );
	}

	public function test_recognises_language_token_in_multi_class(): void {
		$transformer = new CodeBlockTransformer();
		$block       = new ParsedBlock(
			'core/code',
			[ 'className' => 'is-style-default language-typescript line-numbers' ],
			'<pre><code>const x = 1;</code></pre>',
			[],
			[]
		);

		self::assertSame( 'typescript', $transformer->transform( $block )['language'] );
	}
}
