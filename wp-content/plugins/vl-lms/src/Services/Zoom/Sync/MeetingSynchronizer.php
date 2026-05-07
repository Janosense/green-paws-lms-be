<?php

declare(strict_types=1);

namespace VL\LMS\Services\Zoom\Sync;

use VL\LMS\Services\Zoom\Exception\ZoomApiException;
use VL\LMS\Services\Zoom\Exception\ZoomException;
use VL\LMS\Services\Zoom\Settings\ZoomSettingsProvider;
use VL\LMS\Services\Zoom\ZoomClient;
use VL\LMS\Support\Logger;
use WP_Post;

/**
 * Reconciles a `vl_session` / `vl_webinar` post with its Zoom meeting on
 * every `save_post` / `wp_trash_post` / `untrashed_post`.
 *
 * The decision table (see {@see SyncDecision} / {@see SyncReason}) is the
 * canonical contract; every code path returns a {@see SyncResult}, never
 * throws across the public boundary, and always releases the per-post
 * lock in `finally`. The two action hooks are reserved for downstream
 * consumers (Phase 7.6 reminder mailer, Phase 9 audit log):
 *
 *   - `vl_lms_zoom_meeting_synced` — fired on every successful CREATE /
 *     UPDATE / DELETE.
 *   - `vl_lms_zoom_meeting_sync_failed` — fired on every wrapped
 *     {@see \Throwable} caught at the orchestrator boundary.
 *
 * @author Tymofii Synianskyi
 */
class MeetingSynchronizer {

	/**
	 * Post statuses we treat as "the post does not exist yet / not real":
	 * the synchronizer skips them rather than producing partial Zoom state.
	 *
	 * @var list<string>
	 */
	private const array SKIPPED_POST_STATUSES = [ 'auto-draft', 'inherit' ];

	/**
	 * Phase 7.6 demo-seeder bypass flag. The demo CLI wraps post creation
	 * in {@see self::bypass()} so this synchronizer short-circuits with
	 * {@see SyncReason::DEMO_BYPASS} instead of issuing real Zoom API
	 * calls. Static-scoped because `save_post_*` callbacks resolve through
	 * a singleton-ish container instance, and the alternative
	 * (remove_action / re-add) is brittle around exact priorities.
	 */
	private static bool $bypass_active = false;

	private ZoomClient $client;

	private ZoomSettingsProvider $settings;

	private PostMetaAccessor $meta;

	private MeetingPayloadBuilder $builder;

	private DiffDetector $diff;

	private SyncLock $lock;

	private Logger $logger;

	public function __construct(
		ZoomClient $client,
		ZoomSettingsProvider $settings,
		PostMetaAccessor $meta,
		MeetingPayloadBuilder $builder,
		DiffDetector $diff,
		SyncLock $lock,
		Logger $logger
	) {
		$this->client   = $client;
		$this->settings = $settings;
		$this->meta     = $meta;
		$this->builder  = $builder;
		$this->diff     = $diff;
		$this->lock     = $lock;
		$this->logger   = $logger;
	}

	/**
	 * `save_post` adapter — filters by post type internally so the
	 * synchronizer can hook the generic action and still run after
	 * {@see \VL\LMS\Admin\AdminProvider} (which also hooks `save_post`
	 * at priority 10). See {@see MeetingSynchronizerBootstrap}.
	 */
	public function sync_on_save( int $post_id, WP_Post $post, bool $update ): void {
		unset( $update );
		$kind = PostKind::from_post_type( (string) $post->post_type );
		if ( null === $kind ) {
			return;
		}
		$this->sync( $post_id, $post, $kind );
	}

	/**
	 * `wp_trash_post` adapter — re-resolves WP_Post and short-circuits on
	 * unrelated post types.
	 */
	public function sync_on_trash( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return;
		}
		$kind = PostKind::from_post_type( (string) $post->post_type );
		if ( null === $kind ) {
			return;
		}
		$this->sync( $post_id, $post, $kind );
	}

	/**
	 * `untrashed_post` adapter. Treats untrash as a fresh save — if the
	 * meeting was deleted from Zoom on trash (the documented behaviour),
	 * the synchronizer will re-CREATE.
	 */
	public function sync_on_untrash( int $post_id ): void {
		$this->sync_on_trash( $post_id );
	}

	/**
	 * Run `$callback` with the demo-bypass flag set, restoring the prior
	 * value in `finally` so a nested seeder invocation is safe.
	 *
	 * @template T
	 * @param callable(): T $callback
	 * @return T
	 */
	public static function bypass( callable $callback ): mixed {
		$prior               = self::$bypass_active;
		self::$bypass_active = true;
		try {
			return $callback();
		} finally {
			self::$bypass_active = $prior;
		}
	}

	/**
	 * Orchestrator entry point. Always returns; never throws across the
	 * boundary — failures are wrapped into {@see SyncResult::failed()}.
	 */
	public function sync( int $post_id, WP_Post $post, PostKind $kind ): SyncResult {
		if ( self::$bypass_active ) {
			return SyncResult::skipped( $post_id, $kind, SyncReason::DEMO_BYPASS );
		}

		if ( ! $this->settings->get_credentials()->is_configured() ) {
			return SyncResult::skipped( $post_id, $kind, SyncReason::NOT_CONFIGURED );
		}

		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return SyncResult::skipped( $post_id, $kind, SyncReason::REVISION_OR_AUTOSAVE );
		}

		$post_status = (string) $post->post_status;
		if ( in_array( $post_status, self::SKIPPED_POST_STATUSES, true ) ) {
			return SyncResult::skipped( $post_id, $kind, SyncReason::INVALID_POST_STATUS );
		}

		if ( ! $this->lock->try_acquire( $post_id ) ) {
			return SyncResult::skipped( $post_id, $kind, SyncReason::LOCKED );
		}

		try {
			return $this->dispatch( $post_id, $post, $kind, $post_status );
		} finally {
			$this->lock->release( $post_id );
		}
	}

	private function dispatch( int $post_id, WP_Post $post, PostKind $kind, string $post_status ): SyncResult {
		$existing_meeting_id = $this->meta->read_zoom_meeting_id( $post_id, $kind );
		$custom_status       = $this->meta->read_custom_status( $post_id, $kind );
		$is_trashed          = 'trash' === $post_status;
		$is_cancelled        = 'cancelled' === $custom_status;

		if ( $is_trashed || $is_cancelled ) {
			if ( null === $existing_meeting_id ) {
				return SyncResult::noop( $post_id, $kind, SyncReason::CANCELLED_WITHOUT_MEETING );
			}
			return $this->delete( $post_id, $post, $kind, $existing_meeting_id );
		}

		$schedule = [
			'scheduled_start' => $this->meta->read_scheduled_start( $post_id, $kind ),
			'scheduled_end'   => $this->meta->read_scheduled_end( $post_id, $kind ),
		];

		$existing_password = $this->meta->read_zoom_password( $post_id, $kind );
		$payload           = $this->builder->build( $post, $kind, $schedule, $existing_password );

		if ( ! isset( $payload['start_time'] ) ) {
			return SyncResult::noop( $post_id, $kind, SyncReason::MISSING_REQUIRED_META );
		}

		if ( null === $existing_meeting_id ) {
			return $this->create( $post_id, $post, $kind, $payload );
		}

		if ( ! $this->diff->needs_update( $post, $kind, $payload ) ) {
			return SyncResult::noop( $post_id, $kind, SyncReason::NO_DIFF );
		}

		return $this->update( $post_id, $post, $kind, $existing_meeting_id, $payload );
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private function create( int $post_id, WP_Post $post, PostKind $kind, array $payload ): SyncResult {
		unset( $post );
		try {
			$response = $this->client->create_meeting( 'me', $payload );

			$fields = new ZoomMeetingFields(
				isset( $response['id'] ) ? (string) $response['id'] : '',
				isset( $response['join_url'] ) && is_string( $response['join_url'] ) ? $response['join_url'] : '',
				isset( $response['start_url'] ) && is_string( $response['start_url'] ) ? $response['start_url'] : '',
				isset( $response['password'] ) && is_string( $response['password'] ) ? $response['password'] : (string) $payload['password']
			);

			$this->meta->write_zoom_meeting_fields( $post_id, $kind, $fields );
			$this->meta->write_synced_payload_hash( $post_id, $kind, MeetingPayloadBuilder::fingerprint( $payload ) );

			$this->emit_synced( $post_id, $kind, SyncDecision::CREATE, $fields->meeting_id );

			return SyncResult::created( $post_id, $kind, $fields->meeting_id );
		} catch ( \Throwable $e ) {
			return $this->report_failure( $post_id, $kind, SyncDecision::CREATE, $e );
		}
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private function update( int $post_id, WP_Post $post, PostKind $kind, string $meeting_id, array $payload ): SyncResult {
		unset( $post );
		try {
			$this->client->update_meeting( $meeting_id, $payload );

			$this->meta->write_synced_payload_hash( $post_id, $kind, MeetingPayloadBuilder::fingerprint( $payload ) );

			$this->emit_synced( $post_id, $kind, SyncDecision::UPDATE, $meeting_id );

			return SyncResult::updated( $post_id, $kind, $meeting_id );
		} catch ( \Throwable $e ) {
			return $this->report_failure( $post_id, $kind, SyncDecision::UPDATE, $e );
		}
	}

	private function delete( int $post_id, WP_Post $post, PostKind $kind, string $meeting_id ): SyncResult {
		unset( $post );
		try {
			$this->client->delete_meeting( $meeting_id );
		} catch ( ZoomApiException $e ) {
			if ( 404 === $e->http_status ) {
				// Already deleted on Zoom's side — treat as success.
				$this->meta->clear_zoom_meeting_fields( $post_id, $kind );
				$this->emit_synced( $post_id, $kind, SyncDecision::DELETE, null );
				return SyncResult::deleted( $post_id, $kind );
			}
			return $this->report_failure( $post_id, $kind, SyncDecision::DELETE, $e );
		} catch ( \Throwable $e ) {
			return $this->report_failure( $post_id, $kind, SyncDecision::DELETE, $e );
		}

		$this->meta->clear_zoom_meeting_fields( $post_id, $kind );
		$this->emit_synced( $post_id, $kind, SyncDecision::DELETE, null );

		return SyncResult::deleted( $post_id, $kind );
	}

	private function emit_synced( int $post_id, PostKind $kind, SyncDecision $decision, ?string $meeting_id ): void {
		do_action( 'vl_lms_zoom_meeting_synced', $post_id, $kind, $decision, $meeting_id );
	}

	private function report_failure( int $post_id, PostKind $kind, SyncDecision $intended, \Throwable $e ): SyncResult {
		$this->logger->error(
			'Zoom meeting sync failed.',
			[
				'post_id'  => $post_id,
				'kind'     => $kind->value,
				'decision' => $intended->value,
				'message'  => $e->getMessage(),
				'class'    => $e::class,
				'zoom'     => $e instanceof ZoomException ? true : false,
			]
		);

		do_action( 'vl_lms_zoom_meeting_sync_failed', $post_id, $kind, $intended, $e );

		return SyncResult::failed( $post_id, $kind, $intended, $e );
	}
}
