<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Admin\Assignments;

use Brain\Monkey;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Admin\Assignments\GradingQueueTable;
use VL\LMS\Learn\EntityHierarchy;
use VL\LMS\Repositories\AssignmentSubmissionRepository;

final class GradingQueueTableTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_prepare_items_calls_list_pending(): void {
		$repo      = Mockery::mock( AssignmentSubmissionRepository::class );
		$hierarchy = Mockery::mock( EntityHierarchy::class );

		$repo->shouldReceive( 'list_pending' )
			->once()
			->with( 1, 20 )
			->andReturn( [] );
		$repo->shouldReceive( 'count_pending' )
			->once()
			->andReturn( 0 );

		$table = new GradingQueueTable( $repo, $hierarchy );
		$table->prepare_items();

		self::assertSame( [], $table->items );
	}
}
