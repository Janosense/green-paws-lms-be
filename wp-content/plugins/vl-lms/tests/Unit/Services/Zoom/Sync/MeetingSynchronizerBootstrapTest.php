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

	/**
	 * Hooks on `save_post` (not `save_post_{post_type}`) so priority 20
	 * actually runs *after* `AdminProvider::on_save_post` (priority 10
	 * on `save_post`) — WP core fires `save_post_{post_type}` first, so
	 * a typed listener would race ahead of the metabox and read empty
	 * meta on the first save.
	 */
	public function test_register_attaches_all_three_hooks_at_priority_twenty(): void {
		$synchronizer = Mockery::mock( MeetingSynchronizer::class );

		Actions\expectAdded( 'save_post' )->once()->with( Mockery::type( 'array' ), 20, 3 );
		Actions\expectAdded( 'wp_trash_post' )->once()->with( Mockery::type( 'array' ), 20, 1 );
		Actions\expectAdded( 'untrashed_post' )->once()->with( Mockery::type( 'array' ), 20, 1 );

		( new MeetingSynchronizerBootstrap( $synchronizer ) )->register();
	}
}
