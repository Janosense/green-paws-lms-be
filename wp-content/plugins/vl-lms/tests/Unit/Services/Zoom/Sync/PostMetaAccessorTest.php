<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Services\Zoom\Sync;

use PHPUnit\Framework\TestCase;
use VL\LMS\Services\Zoom\Sync\PostKind;
use VL\LMS\Services\Zoom\Sync\PostMetaAccessor;
use VL\LMS\Services\Zoom\Sync\ZoomMeetingFields;
use VL\LMS\Tests\Fixtures\Zoom\Sync\InMemoryPostMetaAccessor;

final class PostMetaAccessorTest extends TestCase {

	public function test_read_zoom_meeting_id_returns_null_when_empty(): void {
		$meta = new InMemoryPostMetaAccessor();

		self::assertNull( $meta->read_zoom_meeting_id( 1, PostKind::SESSION ) );

		$meta->seed( 1, '_vl_session_zoom_meeting_id', 'mtg-1' );
		self::assertSame( 'mtg-1', $meta->read_zoom_meeting_id( 1, PostKind::SESSION ) );
	}

	public function test_read_custom_status_defaults_to_scheduled(): void {
		$meta = new InMemoryPostMetaAccessor();

		self::assertSame( 'scheduled', $meta->read_custom_status( 1, PostKind::SESSION ) );

		$meta->seed( 1, '_vl_session_status', 'cancelled' );
		self::assertSame( 'cancelled', $meta->read_custom_status( 1, PostKind::SESSION ) );

		$meta->seed( 1, '_vl_session_status', 'garbage' );
		self::assertSame( 'scheduled', $meta->read_custom_status( 1, PostKind::SESSION ) );
	}

	public function test_session_and_webinar_meta_are_kept_separate(): void {
		$meta = new InMemoryPostMetaAccessor();
		$meta->seed( 1, '_vl_session_zoom_meeting_id', 'mtg-session' );
		$meta->seed( 1, '_vl_webinar_zoom_meeting_id', 'mtg-webinar' );

		self::assertSame( 'mtg-session', $meta->read_zoom_meeting_id( 1, PostKind::SESSION ) );
		self::assertSame( 'mtg-webinar', $meta->read_zoom_meeting_id( 1, PostKind::WEBINAR ) );
	}

	public function test_write_zoom_meeting_fields_persists_all_four_keys(): void {
		$meta   = new InMemoryPostMetaAccessor();
		$fields = new ZoomMeetingFields( '12345', 'https://zoom.us/j/12345', 'https://zoom.us/s/12345', 'pw1234' );

		$meta->write_zoom_meeting_fields( 7, PostKind::WEBINAR, $fields );

		self::assertSame( '12345', $meta->raw( 7, '_vl_webinar_zoom_meeting_id' ) );
		self::assertSame( 'https://zoom.us/j/12345', $meta->raw( 7, '_vl_webinar_zoom_join_url' ) );
		self::assertSame( 'https://zoom.us/s/12345', $meta->raw( 7, '_vl_webinar_zoom_start_url' ) );
		self::assertSame( 'pw1234', $meta->raw( 7, '_vl_webinar_zoom_password' ) );
	}

	public function test_clear_zoom_meeting_fields_blanks_all_five_keys(): void {
		$meta = new InMemoryPostMetaAccessor();
		$meta->seed( 9, '_vl_session_zoom_meeting_id', 'mtg' );
		$meta->seed( 9, '_vl_session_zoom_join_url', 'https://zoom.us/j/mtg' );
		$meta->seed( 9, '_vl_session_zoom_start_url', 'https://zoom.us/s/mtg' );
		$meta->seed( 9, '_vl_session_zoom_password', 'pw' );
		$meta->seed( 9, '_vl_session_zoom_synced_payload_hash', 'abc' );

		$meta->clear_zoom_meeting_fields( 9, PostKind::SESSION );

		self::assertSame( '', $meta->raw( 9, '_vl_session_zoom_meeting_id' ) );
		self::assertSame( '', $meta->raw( 9, '_vl_session_zoom_join_url' ) );
		self::assertSame( '', $meta->raw( 9, '_vl_session_zoom_start_url' ) );
		self::assertSame( '', $meta->raw( 9, '_vl_session_zoom_password' ) );
		self::assertSame( '', $meta->raw( 9, '_vl_session_zoom_synced_payload_hash' ) );
		self::assertNull( $meta->read_zoom_meeting_id( 9, PostKind::SESSION ) );
	}

	public function test_synced_payload_hash_round_trips(): void {
		$meta = new InMemoryPostMetaAccessor();

		self::assertNull( $meta->read_synced_payload_hash( 1, PostKind::SESSION ) );

		$meta->write_synced_payload_hash( 1, PostKind::SESSION, 'deadbeef' );
		self::assertSame( 'deadbeef', $meta->read_synced_payload_hash( 1, PostKind::SESSION ) );
	}

	public function test_extends_concrete_class_so_test_doubles_are_legal(): void {
		// PHPStan/Mockery sanity: PostMetaAccessor must be subclassable.
		$double = new InMemoryPostMetaAccessor();
		self::assertInstanceOf( PostMetaAccessor::class, $double );
	}
}
