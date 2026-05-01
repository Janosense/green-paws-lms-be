<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Services\Zoom\Sync;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Services\Zoom\Sync\MeetingSynchronizer;
use VL\LMS\Services\Zoom\Sync\MeetingSynchronizerBootstrap;

final class MeetingSynchronizerBootstrapTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_register_attaches_all_four_hooks_at_priority_twenty(): void {
		$synchronizer = Mockery::mock( MeetingSynchronizer::class );

		Actions\expectAdded( 'save_post_vl_session' )->once()->with( Mockery::type( 'array' ), 20, 3 );
		Actions\expectAdded( 'save_post_vl_webinar' )->once()->with( Mockery::type( 'array' ), 20, 3 );
		Actions\expectAdded( 'wp_trash_post' )->once()->with( Mockery::type( 'array' ), 20, 1 );
		Actions\expectAdded( 'untrashed_post' )->once()->with( Mockery::type( 'array' ), 20, 1 );

		( new MeetingSynchronizerBootstrap( $synchronizer ) )->register();
	}
}
