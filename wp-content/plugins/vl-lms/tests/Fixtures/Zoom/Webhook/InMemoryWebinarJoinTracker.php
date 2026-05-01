<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Fixtures\Zoom\Webhook;

use VL\LMS\Services\Zoom\Webhook\WebinarJoinTracker;

/**
 * In-memory double for {@see WebinarJoinTracker}. Stores the transient
 * map in an associative array.
 */
final class InMemoryWebinarJoinTracker extends WebinarJoinTracker {

	/** @var array<string, string> */
	public array $store = [];

	/** @var list<array{key: string, ttl: int}> */
	public array $writes = [];

	protected function read_transient( string $key ): ?string {
		return $this->store[ $key ] ?? null;
	}

	protected function write_transient( string $key, string $value, int $ttl_seconds ): void {
		$this->store[ $key ] = $value;
		$this->writes[]      = [
			'key' => $key,
			'ttl' => $ttl_seconds,
		];
	}

	protected function clear_transient( string $key ): void {
		unset( $this->store[ $key ] );
	}
}
