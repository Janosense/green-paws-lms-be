<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Slug;

use PHPUnit\Framework\TestCase;
use VL\LMS\Slug\CyrillicTransliterator;

final class CyrillicTransliteratorTest extends TestCase {

	private CyrillicTransliterator $transliterator;

	protected function setUp(): void {
		parent::setUp();
		$this->transliterator = new CyrillicTransliterator();
	}

	public function test_passes_pure_ascii_through_unchanged(): void {
		self::assertSame( 'gp-c1-m1-l1-t1', $this->transliterator->slug( 'gp-c1-m1-l1-t1' ) );
	}

	public function test_transliterates_ukrainian_lesson_slug(): void {
		// The exact slug shape that triggered the original bug — Latin
		// course-prefix plus a Ukrainian title. Ukrainian convention maps
		// `и → y` and `і → i`, hence "камери" → "kamery", not "kameri".
		self::assertSame(
			'gp-c1-m1-l1-kamery-sertsia-ta-klapannyi-aparat',
			$this->transliterator->slug( 'gp-c1-m1-l1-камери-серця-та-клапанний-апарат' )
		);
	}

	public function test_handles_uppercase_cyrillic_via_case_folding(): void {
		// Slugs reach this method post-`mb_strtolower`, but defensive case
		// folding inside the transliterator means an uppercase input still
		// yields the lowercase Latin slug instead of leaking unmapped chars.
		self::assertSame( 'kamery-sertsia', $this->transliterator->slug( 'Камери Серця' ) );
	}

	public function test_drops_apostrophe_variants(): void {
		// Ukrainian apostrophe variants (', ʼ, ’) are dropped without
		// inserting a separator — they map to nothing in the passport
		// standard, mirroring how `об'єкт` is rendered "obiekt", not
		// "ob-iekt", in passport / DSTU output.
		self::assertSame( 'obiekt', $this->transliterator->slug( "об'єкт" ) );
		self::assertSame( 'obiekt', $this->transliterator->slug( 'обʼєкт' ) );
		self::assertSame( 'obiekt', $this->transliterator->slug( 'об’єкт' ) );
	}

	public function test_decodes_percent_encoded_input(): void {
		// A slug pasted from a browser URL bar arrives percent-encoded.
		self::assertSame(
			'kamery-sertsia',
			$this->transliterator->slug( '%d0%ba%d0%b0%d0%bc%d0%b5%d1%80%d0%b8-%d1%81%d0%b5%d1%80%d1%86%d1%8f' )
		);
	}

	public function test_collapses_multiple_hyphens_and_trims_edges(): void {
		self::assertSame( 'a-b-c', $this->transliterator->slug( '---a---b---c---' ) );
	}

	public function test_strips_unmapped_non_ascii_characters(): void {
		// CJK / Greek / emoji are out of the locale we support; they should
		// not leak into the slug. Hyphen takes their place so the surrounding
		// segments stay separable.
		self::assertSame( 'kurs-a', $this->transliterator->slug( 'курс-Ω-A' ) );
	}

	public function test_idempotent_on_repeated_application(): void {
		$once  = $this->transliterator->slug( 'kameri-серця' );
		$twice = $this->transliterator->slug( $once );
		self::assertSame( $once, $twice );
	}

	public function test_russian_specific_letters_are_handled(): void {
		// Mixed-locale safety net — even though content is Ukrainian-first,
		// imported posts could carry Russian letters.
		self::assertSame( 'eshche-tsy-obekt', $this->transliterator->slug( 'ещё-цы-объект' ) );
	}

	public function test_empty_input_returns_empty_string(): void {
		self::assertSame( '', $this->transliterator->slug( '' ) );
	}

	public function test_input_consisting_only_of_unmappable_characters_returns_empty(): void {
		self::assertSame( '', $this->transliterator->slug( '——' ) );
	}
}
