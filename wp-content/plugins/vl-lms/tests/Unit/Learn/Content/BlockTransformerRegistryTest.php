<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Learn\Content;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use VL\LMS\Learn\Content\BlockTransformer;
use VL\LMS\Learn\Content\BlockTransformerRegistry;
use VL\LMS\Learn\Content\Blocks\HtmlFallbackBlockTransformer;
use VL\LMS\Learn\Content\ParsedBlock;

final class BlockTransformerRegistryTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_kses_post' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function namedTransformer( string $supports_name, array $shape ): BlockTransformer {
		return new class( $supports_name, $shape ) implements BlockTransformer {

			/**
			 * @param array<string, mixed> $shape
			 */
			public function __construct( private string $supports_name, private array $shape ) {
			}

			public function supports( string $block_name ): bool {
				return $block_name === $this->supports_name;
			}

			public function transform( ParsedBlock $block ): array {
				return $this->shape;
			}
		};
	}

	public function test_first_matching_transformer_wins(): void {
		$first    = $this->namedTransformer(
			'core/paragraph',
			[
				'type' => 'paragraph',
				'tag'  => 'first',
			]
		);
		$second   = $this->namedTransformer(
			'core/paragraph',
			[
				'type' => 'paragraph',
				'tag'  => 'second',
			]
		);
		$registry = new BlockTransformerRegistry( [ $first, $second, new HtmlFallbackBlockTransformer() ] );

		$result = $registry->transform( new ParsedBlock( 'core/paragraph', [], '<p>x</p>', [], [] ) );

		self::assertSame( 'first', $result['tag'] );
	}

	public function test_unknown_block_falls_through_to_html_fallback(): void {
		$registry = new BlockTransformerRegistry(
			[
				$this->namedTransformer(
					'core/paragraph',
					[
						'type' => 'paragraph',
						'html' => '<p>x</p>',
					]
				),
				new HtmlFallbackBlockTransformer(),
			]
		);

		$result = $registry->transform( new ParsedBlock( 'plugin-x/widget', [], '<div>raw</div>', [], [] ) );

		self::assertSame( 'html', $result['type'] );
		self::assertSame( '<div>raw</div>', $result['html'] );
	}

	public function test_registry_with_no_matching_transformer_does_not_throw(): void {
		$registry = new BlockTransformerRegistry( [] );

		$result = $registry->transform( new ParsedBlock( 'plugin-x/widget', [], '<div>raw</div>', [], [] ) );

		self::assertSame(
			[
				'type' => 'html',
				'html' => '',
			],
			$result
		);
	}

	public function test_registry_dispatches_known_block_to_owning_transformer(): void {
		$registry = new BlockTransformerRegistry(
			[
				$this->namedTransformer(
					'core/heading',
					[
						'type'  => 'heading',
						'level' => 2,
					]
				),
				new HtmlFallbackBlockTransformer(),
			]
		);

		$result = $registry->transform( new ParsedBlock( 'core/heading', [], '<h2>Title</h2>', [], [] ) );

		self::assertSame( 'heading', $result['type'] );
		self::assertSame( 2, $result['level'] );
	}
}
