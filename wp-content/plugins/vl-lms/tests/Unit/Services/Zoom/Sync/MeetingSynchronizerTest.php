<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Services\Zoom\Sync;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Services\Zoom\Exception\ZoomApiException;
use VL\LMS\Services\Zoom\Exception\ZoomAuthException;
use VL\LMS\Services\Zoom\Settings\ZoomCredentials;
use VL\LMS\Services\Zoom\Sync\DiffDetector;
use VL\LMS\Services\Zoom\Sync\MeetingPayloadBuilder;
use VL\LMS\Services\Zoom\Sync\MeetingSynchronizer;
use VL\LMS\Services\Zoom\Sync\PasswordGenerator;
use VL\LMS\Services\Zoom\Sync\PostKind;
use VL\LMS\Services\Zoom\Sync\SyncDecision;
use VL\LMS\Services\Zoom\Sync\SyncReason;
use VL\LMS\Support\Logger;
use VL\LMS\Tests\Fixtures\Zoom\Sync\InMemoryPostMetaAccessor;
use VL\LMS\Tests\Fixtures\Zoom\Sync\InMemorySyncLock;
use VL\LMS\Tests\Fixtures\Zoom\Sync\RecordingZoomClient;
use VL\LMS\Tests\Fixtures\Zoom\Sync\StubZoomSettingsProvider;
use WP_Post;

final class MeetingSynchronizerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private RecordingZoomClient $client;

	private InMemoryPostMetaAccessor $meta;

	private InMemorySyncLock $lock;

	private MeetingSynchronizer $sync;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'wp_timezone_string' )->justReturn( 'Europe/Kyiv' );
		Functions\when( 'wp_strip_all_tags' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias(
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Brain Monkey shim.
			static fn ( $value ): string|false => json_encode( $value )
		);
		Functions\when( 'wp_is_post_autosave' )->justReturn( false );
		Functions\when( 'wp_is_post_revision' )->justReturn( false );

		$this->client = new RecordingZoomClient();
		$this->meta   = new InMemoryPostMetaAccessor();
		$this->lock   = new InMemorySyncLock();

		$this->sync = $this->build_synchronizer( true );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function build_synchronizer( bool $configured ): MeetingSynchronizer {
		$creds = $configured
			? new ZoomCredentials( 'acc', 'cid', 'csec', 'whk' )
			: new ZoomCredentials( '', '', '', '' );

		$pw_gen = new class() extends PasswordGenerator {
			public function generate(): string {
				return 'PWPWPWPWPW';
			}
		};

		return new MeetingSynchronizer(
			$this->client,
			new StubZoomSettingsProvider( $creds ),
			$this->meta,
			new MeetingPayloadBuilder( $pw_gen ),
			new DiffDetector( $this->meta ),
			$this->lock,
			new Logger( 'test' )
		);
	}

	private function post( int $id, string $title = 'Demo', string $status = 'publish', string $type = 'vl_session' ): WP_Post {
		$p               = Mockery::mock( 'WP_Post' );
		$p->ID           = $id;
		$p->post_title   = $title;
		$p->post_content = '';
		$p->post_status  = $status;
		$p->post_type    = $type;
		$p->post_author  = 1;
		return $p;
	}

	private function seed_schedule( int $post_id, PostKind $kind, string $start = '2026-05-10T09:00:00Z', string $end = '2026-05-10T10:00:00Z' ): void {
		$this->meta->seed( $post_id, $kind->meta_key_scheduled_start(), $start );
		$this->meta->seed( $post_id, $kind->meta_key_scheduled_end(), $end );
	}

	// -------- SKIPPED branches ----------------------------------------------

	public function test_skipped_when_credentials_missing(): void {
		$sync = $this->build_synchronizer( false );

		$result = $sync->sync( 1, $this->post( 1 ), PostKind::SESSION );

		self::assertSame( SyncDecision::SKIPPED, $result->decision );
		self::assertSame( SyncReason::NOT_CONFIGURED, $result->reason );
		self::assertSame( [], $this->client->calls );
		self::assertArrayNotHasKey( 1, $this->lock->held );
	}

	public function test_skipped_on_revision_or_autosave(): void {
		Functions\when( 'wp_is_post_autosave' )->justReturn( true );

		$result = $this->sync->sync( 1, $this->post( 1 ), PostKind::SESSION );

		self::assertSame( SyncReason::REVISION_OR_AUTOSAVE, $result->reason );
		self::assertSame( SyncDecision::SKIPPED, $result->decision );
	}

	public function test_skipped_on_invalid_post_status(): void {
		$result = $this->sync->sync( 1, $this->post( 1, 'Demo', 'auto-draft' ), PostKind::SESSION );

		self::assertSame( SyncReason::INVALID_POST_STATUS, $result->reason );
		self::assertSame( [], $this->client->calls );
	}

	public function test_skipped_when_lock_already_held(): void {
		$this->lock->held[1] = true;

		$result = $this->sync->sync( 1, $this->post( 1 ), PostKind::SESSION );

		self::assertSame( SyncReason::LOCKED, $result->reason );
		self::assertSame( SyncDecision::SKIPPED, $result->decision );
		self::assertSame( [], $this->client->calls );
	}

	// -------- NOOP branches -------------------------------------------------

	public function test_noop_when_cancelled_post_has_no_meeting(): void {
		$this->meta->seed( 1, '_vl_session_status', 'cancelled' );

		$result = $this->sync->sync( 1, $this->post( 1 ), PostKind::SESSION );

		self::assertSame( SyncDecision::NOOP, $result->decision );
		self::assertSame( SyncReason::CANCELLED_WITHOUT_MEETING, $result->reason );
		self::assertSame( [], $this->client->calls );
	}

	public function test_noop_when_trashed_post_has_no_meeting(): void {
		$result = $this->sync->sync( 1, $this->post( 1, 'Demo', 'trash' ), PostKind::SESSION );

		self::assertSame( SyncReason::CANCELLED_WITHOUT_MEETING, $result->reason );
		self::assertSame( [], $this->client->calls );
	}

	public function test_noop_when_required_meta_missing(): void {
		// No scheduled_start seeded.
		$result = $this->sync->sync( 1, $this->post( 1 ), PostKind::SESSION );

		self::assertSame( SyncReason::MISSING_REQUIRED_META, $result->reason );
		self::assertSame( SyncDecision::NOOP, $result->decision );
		self::assertSame( [], $this->client->calls );
	}

	public function test_noop_when_no_diff(): void {
		$this->seed_schedule( 1, PostKind::SESSION );
		$this->meta->seed( 1, '_vl_session_zoom_meeting_id', 'mtg-existing' );
		$this->meta->seed( 1, '_vl_session_zoom_password', 'EXISTING12' );

		// Pre-compute the canonical hash so DiffDetector returns false.
		$payload = ( new MeetingPayloadBuilder(
			new class() extends PasswordGenerator {
				public function generate(): string {
					return 'PWPWPWPWPW';
				}
			}
		) )->build(
			$this->post( 1 ),
			PostKind::SESSION,
			[
				'scheduled_start' => '2026-05-10T09:00:00Z',
				'scheduled_end'   => '2026-05-10T10:00:00Z',
			],
			'EXISTING12'
		);
		$this->meta->seed( 1, '_vl_session_zoom_synced_payload_hash', MeetingPayloadBuilder::fingerprint( $payload ) );

		$result = $this->sync->sync( 1, $this->post( 1 ), PostKind::SESSION );

		self::assertSame( SyncDecision::NOOP, $result->decision );
		self::assertSame( SyncReason::NO_DIFF, $result->reason );
		self::assertSame( [], $this->client->calls );
	}

	// -------- CREATE branch -------------------------------------------------

	public function test_create_writes_meta_and_emits_synced_action(): void {
		Actions\expectDone( 'vl_lms_zoom_meeting_synced' )->once();

		$this->seed_schedule( 1, PostKind::SESSION );

		$result = $this->sync->sync( 1, $this->post( 1 ), PostKind::SESSION );

		self::assertSame( SyncDecision::CREATE, $result->decision );
		self::assertSame( 'mtg-new', $result->meeting_id );
		self::assertCount( 1, $this->client->calls );
		self::assertSame( 'create_meeting', $this->client->calls[0]['method'] );

		self::assertSame( 'mtg-new', $this->meta->raw( 1, '_vl_session_zoom_meeting_id' ) );
		self::assertSame( 'https://zoom.us/j/mtg-new', $this->meta->raw( 1, '_vl_session_zoom_join_url' ) );
		self::assertSame( 'https://zoom.us/s/mtg-new', $this->meta->raw( 1, '_vl_session_zoom_start_url' ) );
		self::assertSame( 'returned-pw', $this->meta->raw( 1, '_vl_session_zoom_password' ) );
		self::assertNotSame( '', $this->meta->raw( 1, '_vl_session_zoom_synced_payload_hash' ) );

		// Lock must be released.
		self::assertArrayNotHasKey( 1, $this->lock->held );
		self::assertSame( [ 1 ], $this->lock->release_calls );
	}

	// -------- UPDATE branch -------------------------------------------------

	public function test_update_uses_existing_password_and_updates_hash(): void {
		Actions\expectDone( 'vl_lms_zoom_meeting_synced' )->once();

		$this->seed_schedule( 1, PostKind::SESSION );
		$this->meta->seed( 1, '_vl_session_zoom_meeting_id', 'mtg-existing' );
		$this->meta->seed( 1, '_vl_session_zoom_password', 'EXISTING12' );
		$this->meta->seed( 1, '_vl_session_zoom_synced_payload_hash', 'old-hash' );

		$result = $this->sync->sync( 1, $this->post( 1, 'Renamed' ), PostKind::SESSION );

		self::assertSame( SyncDecision::UPDATE, $result->decision );
		self::assertSame( 'mtg-existing', $result->meeting_id );
		self::assertCount( 1, $this->client->calls );
		self::assertSame( 'update_meeting', $this->client->calls[0]['method'] );
		self::assertSame( 'mtg-existing', $this->client->calls[0]['args']['meeting_id'] );

		// Existing password must be passed through, not regenerated.
		self::assertSame( 'EXISTING12', $this->client->calls[0]['args']['request']['password'] );

		// Hash flips.
		self::assertNotSame( 'old-hash', $this->meta->raw( 1, '_vl_session_zoom_synced_payload_hash' ) );
	}

	// -------- DELETE branches -----------------------------------------------

	public function test_delete_on_trash_clears_meta(): void {
		Actions\expectDone( 'vl_lms_zoom_meeting_synced' )->once();

		$this->meta->seed( 1, '_vl_session_zoom_meeting_id', 'mtg-doomed' );

		$result = $this->sync->sync( 1, $this->post( 1, 'X', 'trash' ), PostKind::SESSION );

		self::assertSame( SyncDecision::DELETE, $result->decision );
		self::assertCount( 1, $this->client->calls );
		self::assertSame( 'delete_meeting', $this->client->calls[0]['method'] );
		self::assertSame( 'mtg-doomed', $this->client->calls[0]['args']['meeting_id'] );

		self::assertSame( '', $this->meta->raw( 1, '_vl_session_zoom_meeting_id' ) );
	}

	public function test_delete_on_custom_status_cancelled(): void {
		Actions\expectDone( 'vl_lms_zoom_meeting_synced' )->once();

		$this->meta->seed( 1, '_vl_session_zoom_meeting_id', 'mtg-cancelled' );
		$this->meta->seed( 1, '_vl_session_status', 'cancelled' );

		$result = $this->sync->sync( 1, $this->post( 1 ), PostKind::SESSION );

		self::assertSame( SyncDecision::DELETE, $result->decision );
		self::assertSame( 'delete_meeting', $this->client->calls[0]['method'] );
		self::assertSame( '', $this->meta->raw( 1, '_vl_session_zoom_meeting_id' ) );
	}

	public function test_delete_treats_404_as_success(): void {
		Actions\expectDone( 'vl_lms_zoom_meeting_synced' )->once();
		Actions\expectDone( 'vl_lms_zoom_meeting_sync_failed' )->never();

		$this->meta->seed( 1, '_vl_session_zoom_meeting_id', 'mtg-gone' );
		$this->client->delete_throws = new ZoomApiException( 'gone', 404 );

		$result = $this->sync->sync( 1, $this->post( 1, 'X', 'trash' ), PostKind::SESSION );

		self::assertSame( SyncDecision::DELETE, $result->decision );
		self::assertNull( $result->exception );
		self::assertSame( '', $this->meta->raw( 1, '_vl_session_zoom_meeting_id' ) );
	}

	// -------- Failure handling ----------------------------------------------

	public function test_create_failure_leaves_meta_untouched_and_fires_failed_action(): void {
		Actions\expectDone( 'vl_lms_zoom_meeting_synced' )->never();
		Actions\expectDone( 'vl_lms_zoom_meeting_sync_failed' )->once();

		$this->seed_schedule( 1, PostKind::SESSION );
		$this->client->create_throws = new ZoomApiException( 'boom', 500 );

		$result = $this->sync->sync( 1, $this->post( 1 ), PostKind::SESSION );

		self::assertSame( SyncDecision::CREATE, $result->decision );
		self::assertNotNull( $result->exception );
		self::assertSame( '', $this->meta->raw( 1, '_vl_session_zoom_meeting_id' ) );
		self::assertSame( '', $this->meta->raw( 1, '_vl_session_zoom_synced_payload_hash' ) );

		// Lock still released.
		self::assertArrayNotHasKey( 1, $this->lock->held );
	}

	public function test_create_zoom_auth_failure_is_caught(): void {
		Actions\expectDone( 'vl_lms_zoom_meeting_sync_failed' )->once();

		$this->seed_schedule( 1, PostKind::SESSION );
		$this->client->create_throws = new ZoomAuthException( 'auth' );

		$result = $this->sync->sync( 1, $this->post( 1 ), PostKind::SESSION );

		self::assertInstanceOf( ZoomAuthException::class, $result->exception );
		self::assertSame( SyncDecision::CREATE, $result->decision );
	}

	public function test_lock_released_in_finally_when_dispatch_throws_unexpectedly(): void {
		// A defensive test: even if we somehow surface a non-Zoom throwable
		// from the create path, the lock must release.
		Actions\expectDone( 'vl_lms_zoom_meeting_sync_failed' )->once();

		$this->seed_schedule( 1, PostKind::SESSION );
		$this->client->create_throws = new \LogicException( 'boom' );

		$result = $this->sync->sync( 1, $this->post( 1 ), PostKind::SESSION );

		self::assertNotNull( $result->exception );
		self::assertArrayNotHasKey( 1, $this->lock->held );
	}

	// -------- Hook adapters -------------------------------------------------

	public function test_sync_on_save_short_circuits_for_unrelated_post_types(): void {
		$this->sync->sync_on_save( 1, $this->post( 1, 'X', 'publish', 'post' ), false );

		self::assertSame( [], $this->client->calls );
	}

	public function test_sync_on_save_routes_session_to_session_kind(): void {
		Actions\expectDone( 'vl_lms_zoom_meeting_synced' )->once();
		$this->seed_schedule( 1, PostKind::SESSION );

		$this->sync->sync_on_save( 1, $this->post( 1, 'X', 'publish', 'vl_session' ), false );

		self::assertSame( 'create_meeting', $this->client->calls[0]['method'] );
		self::assertSame( 'mtg-new', $this->meta->raw( 1, '_vl_session_zoom_meeting_id' ) );
	}
}
