<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Admin\MetaBoxes\ChildList;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Admin\MetaBoxes\ChildList\QuestionListMetaBox;

final class QuestionListMetaBoxTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_register_calls_add_meta_box_with_correct_post_type(): void {
		$captured = [];
		Functions\when( 'add_meta_box' )->alias(
			static function ( ...$args ) use ( &$captured ): void {
				$captured[] = $args;
			}
		);

		( new QuestionListMetaBox() )->register();

		self::assertCount( 1, $captured );
		self::assertSame( 'vl_lms_question_list', $captured[0][0] );
		self::assertSame( 'Питання', $captured[0][1] );
		self::assertSame( 'vl_quiz', $captured[0][3] );
	}
}
