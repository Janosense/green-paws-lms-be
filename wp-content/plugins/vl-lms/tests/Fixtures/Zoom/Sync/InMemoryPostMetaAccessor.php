<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Fixtures\Zoom\Sync;

use VL\LMS\Services\Zoom\Sync\PostMetaAccessor;

/**
 * In-memory double for {@see PostMetaAccessor}. Stores meta in a
 * `[post_id][meta_key] => string` table. Tests seed via {@see seed()}
 * and inspect via {@see raw()}.
 */
final class InMemoryPostMetaAccessor extends PostMetaAccessor {

	/** @var array<int, array<string, string>> */
	private array $store = [];

	public function seed( int $post_id, string $meta_key, string $value ): void {
		$this->store[ $post_id ][ $meta_key ] = $value;
	}

	public function raw( int $post_id, string $meta_key ): string {
		return $this->store[ $post_id ][ $meta_key ] ?? '';
	}

	protected function read_meta( int $post_id, string $meta_key ): mixed {
		return $this->store[ $post_id ][ $meta_key ] ?? '';
	}

	protected function write_meta( int $post_id, string $meta_key, string $value ): void {
		$this->store[ $post_id ][ $meta_key ] = $value;
	}
}
