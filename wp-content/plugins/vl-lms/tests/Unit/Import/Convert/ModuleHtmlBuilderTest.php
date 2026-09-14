<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Import\Convert;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Import\Convert\ModuleHtmlBuilder;
use VL\LMS\Import\Document\ModuleSpec;

final class ModuleHtmlBuilderTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'esc_html' )->alias( static fn ( string $text ): string => htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_the_title_is_one_escaped_paragraph(): void {
		$module = new ModuleSpec( 1, 'Підготовка <b> & **основи**', 49, [], null );

		self::assertSame( "<p>Підготовка &lt;b&gt; &amp; **основи**</p>\n", ( new ModuleHtmlBuilder() )->build( $module ) );
	}
}
