<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Fixtures\Zoom\Sync;

use VL\LMS\Services\Zoom\Sync\SyncLock;

/**
 * In-memory double for {@see SyncLock} that records every acquire / release.
 */
final class InMemorySyncLock extends SyncLock {

	/** @var array<int, true> */
	public array $held = [];

	/** @var list<int> */
	public array $acquire_calls = [];

	/** @var list<int> */
	public array $release_calls = [];

	protected function read_lock( int $post_id ): ?string {
		$this->acquire_calls[] = $post_id;
		return isset( $this->held[ $post_id ] ) ? '1' : null;
	}

	protected function write_lock( int $post_id, int $ttl_seconds ): void {
		$this->held[ $post_id ] = true;
	}

	protected function clear_lock( int $post_id ): void {
		$this->release_calls[] = $post_id;
		unset( $this->held[ $post_id ] );
	}
}
