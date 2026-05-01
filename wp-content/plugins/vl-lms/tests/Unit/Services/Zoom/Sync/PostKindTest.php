<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Services\Zoom\Sync;

use PHPUnit\Framework\TestCase;
use VL\LMS\Services\Zoom\Sync\PostKind;

final class PostKindTest extends TestCase {

	public function test_from_post_type_resolves_known_slugs(): void {
		self::assertSame( PostKind::SESSION, PostKind::from_post_type( 'vl_session' ) );
		self::assertSame( PostKind::WEBINAR, PostKind::from_post_type( 'vl_webinar' ) );
	}

	public function test_from_post_type_returns_null_for_unrelated_types(): void {
		self::assertNull( PostKind::from_post_type( 'post' ) );
		self::assertNull( PostKind::from_post_type( 'vl_course' ) );
		self::assertNull( PostKind::from_post_type( '' ) );
	}

	public function test_session_meta_keys_use_session_prefix(): void {
		$kind = PostKind::SESSION;

		self::assertSame( '_vl_session_status', $kind->meta_key_status() );
		self::assertSame( '_vl_session_scheduled_start', $kind->meta_key_scheduled_start() );
		self::assertSame( '_vl_session_scheduled_end', $kind->meta_key_scheduled_end() );
		self::assertSame( '_vl_session_zoom_meeting_id', $kind->meta_key_zoom_meeting_id() );
		self::assertSame( '_vl_session_zoom_join_url', $kind->meta_key_zoom_join_url() );
		self::assertSame( '_vl_session_zoom_start_url', $kind->meta_key_zoom_start_url() );
		self::assertSame( '_vl_session_zoom_password', $kind->meta_key_zoom_password() );
		self::assertSame( '_vl_session_zoom_synced_payload_hash', $kind->meta_key_synced_payload_hash() );
		self::assertSame( '_vl_session_recording_url', $kind->meta_key_recording_url() );
		self::assertNull( $kind->meta_key_recording_available_until() );
	}

	public function test_webinar_meta_keys_use_webinar_prefix(): void {
		$kind = PostKind::WEBINAR;

		self::assertSame( '_vl_webinar_status', $kind->meta_key_status() );
		self::assertSame( '_vl_webinar_scheduled_start', $kind->meta_key_scheduled_start() );
		self::assertSame( '_vl_webinar_scheduled_end', $kind->meta_key_scheduled_end() );
		self::assertSame( '_vl_webinar_zoom_meeting_id', $kind->meta_key_zoom_meeting_id() );
		self::assertSame( '_vl_webinar_zoom_join_url', $kind->meta_key_zoom_join_url() );
		self::assertSame( '_vl_webinar_zoom_start_url', $kind->meta_key_zoom_start_url() );
		self::assertSame( '_vl_webinar_zoom_password', $kind->meta_key_zoom_password() );
		self::assertSame( '_vl_webinar_zoom_synced_payload_hash', $kind->meta_key_synced_payload_hash() );
		self::assertSame( '_vl_webinar_recording_url', $kind->meta_key_recording_url() );
		self::assertSame( '_vl_webinar_recording_available_until', $kind->meta_key_recording_available_until() );
	}
}
