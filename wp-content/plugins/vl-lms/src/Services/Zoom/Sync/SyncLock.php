<?php

declare(strict_types=1);

namespace VL\LMS\Services\Zoom\Sync;

/**
 * Per-post mutex backed by a WP transient.
 *
 * The 30-second TTL covers the worst-case Zoom round-trip; the lock is
 * always released in {@see MeetingSynchronizer}'s `finally`, so the TTL
 * is a fallback for crashes mid-sync — not the normal release path. A
 * concurrent `save_post` for the same post sees the existing transient
 * and returns {@see SyncReason::LOCKED}, producing exactly one outbound
 * Zoom call regardless of how many concurrent saves arrive.
 *
 * Concrete (not final) so unit tests can subclass and override the three
 * transient seams without touching the WP transient API.
 *
 * @author Tymofii Synianskyi
 */
class SyncLock {

	private const string KEY_PREFIX = 'vl_lms_zoom_sync_lock_';

	private const int TTL_SECONDS = 30;

	public function try_acquire( int $post_id ): bool {
		if ( null !== $this->read_lock( $post_id ) ) {
			return false;
		}
		$this->write_lock( $post_id, self::TTL_SECONDS );
		return true;
	}

	public function release( int $post_id ): void {
		$this->clear_lock( $post_id );
	}

	private function key( int $post_id ): string {
		return self::KEY_PREFIX . $post_id;
	}

	protected function read_lock( int $post_id ): ?string {
		$value = get_transient( $this->key( $post_id ) );
		if ( false === $value ) {
			return null;
		}
		return is_string( $value ) ? $value : '1';
	}

	protected function write_lock( int $post_id, int $ttl_seconds ): void {
		set_transient( $this->key( $post_id ), '1', $ttl_seconds );
	}

	protected function clear_lock( int $post_id ): void {
		delete_transient( $this->key( $post_id ) );
	}
}
