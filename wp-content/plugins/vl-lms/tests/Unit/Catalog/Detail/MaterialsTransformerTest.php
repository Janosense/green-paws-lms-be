<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Catalog\Detail;

use PHPUnit\Framework\TestCase;
use VL\LMS\Catalog\Detail\MaterialsTransformer;

final class MaterialsTransformerTest extends TestCase {

	private MaterialsTransformer $transformer;

	protected function setUp(): void {
		parent::setUp();
		$this->transformer = new MaterialsTransformer();
	}

	public function test_passes_through_well_formed_list(): void {
		$result = $this->transformer->transform(
			[
				[
					'url'  => 'https://example.test/slides.pdf',
					'name' => 'Slides',
					'size' => 1234567,
				],
				[
					'url'  => 'https://example.test/notes.pdf',
					'name' => 'Notes',
					'size' => 4321,
				],
			]
		);

		self::assertCount( 2, $result );
		self::assertSame( 'https://example.test/slides.pdf', $result[0]['url'] );
		self::assertSame( 'Slides', $result[0]['name'] );
		self::assertSame( 1234567, $result[0]['size'] );
	}

	public function test_drops_entry_with_missing_url(): void {
		$result = $this->transformer->transform(
			[
				[
					'url'  => '',
					'name' => 'No URL',
					'size' => 100,
				],
				[
					'url'  => 'https://example.test/ok.pdf',
					'name' => 'OK',
					'size' => 1,
				],
			]
		);

		self::assertCount( 1, $result );
		self::assertSame( 'https://example.test/ok.pdf', $result[0]['url'] );
	}

	public function test_drops_entry_when_url_key_is_absent(): void {
		$result = $this->transformer->transform(
			[
				[
					'name' => 'No URL Key',
					'size' => 100,
				],
			]
		);

		self::assertSame( [], $result );
	}

	public function test_size_coerces_to_int(): void {
		$result = $this->transformer->transform(
			[
				[
					'url'  => 'https://example.test/a.pdf',
					'name' => 'A',
					'size' => '4321',
				],
			]
		);

		self::assertSame( 4321, $result[0]['size'] );
	}

	public function test_negative_size_clamps_to_zero(): void {
		$result = $this->transformer->transform(
			[
				[
					'url'  => 'https://example.test/a.pdf',
					'name' => 'A',
					'size' => -100,
				],
			]
		);

		self::assertSame( 0, $result[0]['size'] );
	}

	public function test_empty_input_yields_empty_array(): void {
		self::assertSame( [], $this->transformer->transform( [] ) );
	}

	public function test_non_array_input_yields_empty_array(): void {
		self::assertSame( [], $this->transformer->transform( null ) );
		self::assertSame( [], $this->transformer->transform( '' ) );
		self::assertSame( [], $this->transformer->transform( 'string' ) );
		self::assertSame( [], $this->transformer->transform( 42 ) );
	}

	public function test_skips_non_array_items(): void {
		$result = $this->transformer->transform(
			[
				'just-a-string',
				42,
				[
					'url'  => 'https://example.test/a.pdf',
					'name' => 'A',
					'size' => 1,
				],
			]
		);

		self::assertCount( 1, $result );
		self::assertSame( 'https://example.test/a.pdf', $result[0]['url'] );
	}

	public function test_missing_optional_keys_default(): void {
		$result = $this->transformer->transform(
			[
				[ 'url' => 'https://example.test/a.pdf' ],
			]
		);

		self::assertSame( '', $result[0]['name'] );
		self::assertSame( 0, $result[0]['size'] );
	}
}
