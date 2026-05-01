<?php

declare(strict_types=1);

namespace VL\LMS\Services\Zoom\Sync;

/**
 * Single chokepoint for Zoom-related post-meta access.
 *
 * Concrete (not final) so unit tests can subclass and override the
 * three protected seams ({@see read_meta()}, {@see write_meta()},
 * {@see delete_meta()}) without round-tripping through `get_post_meta`
 * / `update_post_meta`.
 *
 * Empty-string is the "no value" sentinel for every Zoom meta field —
 * we keep the keys around (set to '') instead of `delete_post_meta`'ing
 * them so the post-meta schema stays stable for downstream consumers
 * that rely on a meta-key probe.
 *
 * @author Tymofii Synianskyi
 */
class PostMetaAccessor {

	public function read_zoom_meeting_id( int $post_id, PostKind $kind ): ?string {
		$value = $this->read_string( $post_id, $kind->meta_key_zoom_meeting_id() );
		return '' === $value ? null : $value;
	}

	public function read_zoom_password( int $post_id, PostKind $kind ): ?string {
		$value = $this->read_string( $post_id, $kind->meta_key_zoom_password() );
		return '' === $value ? null : $value;
	}

	/**
	 * Returns one of `scheduled` | `live` | `completed` | `cancelled`.
	 * Falls back to `scheduled` when the meta is absent or unrecognised.
	 */
	public function read_custom_status( int $post_id, PostKind $kind ): string {
		$value = $this->read_string( $post_id, $kind->meta_key_status() );
		if ( in_array( $value, [ 'scheduled', 'live', 'completed', 'cancelled' ], true ) ) {
			return $value;
		}
		return 'scheduled';
	}

	public function read_scheduled_start( int $post_id, PostKind $kind ): ?string {
		$value = $this->read_string( $post_id, $kind->meta_key_scheduled_start() );
		return '' === $value ? null : $value;
	}

	public function read_scheduled_end( int $post_id, PostKind $kind ): ?string {
		$value = $this->read_string( $post_id, $kind->meta_key_scheduled_end() );
		return '' === $value ? null : $value;
	}

	public function read_synced_payload_hash( int $post_id, PostKind $kind ): ?string {
		$value = $this->read_string( $post_id, $kind->meta_key_synced_payload_hash() );
		return '' === $value ? null : $value;
	}

	public function write_zoom_meeting_fields( int $post_id, PostKind $kind, ZoomMeetingFields $fields ): void {
		$this->write_meta( $post_id, $kind->meta_key_zoom_meeting_id(), $fields->meeting_id );
		$this->write_meta( $post_id, $kind->meta_key_zoom_join_url(), $fields->join_url );
		$this->write_meta( $post_id, $kind->meta_key_zoom_start_url(), $fields->start_url );
		$this->write_meta( $post_id, $kind->meta_key_zoom_password(), $fields->password );
	}

	public function write_synced_payload_hash( int $post_id, PostKind $kind, string $hash ): void {
		$this->write_meta( $post_id, $kind->meta_key_synced_payload_hash(), $hash );
	}

	/**
	 * Clears all five Zoom-managed meta keys (the four user-visible ones
	 * plus the synced-payload-hash side-channel) by writing empty strings.
	 */
	public function clear_zoom_meeting_fields( int $post_id, PostKind $kind ): void {
		$this->write_meta( $post_id, $kind->meta_key_zoom_meeting_id(), '' );
		$this->write_meta( $post_id, $kind->meta_key_zoom_join_url(), '' );
		$this->write_meta( $post_id, $kind->meta_key_zoom_start_url(), '' );
		$this->write_meta( $post_id, $kind->meta_key_zoom_password(), '' );
		$this->write_meta( $post_id, $kind->meta_key_synced_payload_hash(), '' );
	}

	private function read_string( int $post_id, string $meta_key ): string {
		$raw = $this->read_meta( $post_id, $meta_key );
		return is_string( $raw ) ? $raw : '';
	}

	/**
	 * Indirected so unit tests can subclass and override without poking
	 * at the WP post-meta API.
	 */
	protected function read_meta( int $post_id, string $meta_key ): mixed {
		return get_post_meta( $post_id, $meta_key, true );
	}

	protected function write_meta( int $post_id, string $meta_key, string $value ): void {
		update_post_meta( $post_id, $meta_key, $value );
	}
}
