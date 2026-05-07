<?php

declare(strict_types=1);

namespace VL\LMS\Services\Zoom\Sync;

/**
 * Wires {@see MeetingSynchronizer}'s adapter methods to the WP
 * post-lifecycle hooks: `save_post`, `wp_trash_post`, and
 * `untrashed_post`. The save adapter filters by post type internally
 * via {@see MeetingSynchronizer::sync_on_save()}.
 *
 * Lives separately from the orchestrator so unit tests can exercise
 * `sync()` without poking at WP's hook system.
 *
 * Hooked on `save_post` (not `save_post_{post_type}`) so the priority
 * is meaningful relative to {@see \VL\LMS\Admin\AdminProvider}, which
 * routes typed metabox writes through `save_post` priority 10. WP
 * core fires `save_post_{post_type}` *before* `save_post`, so a
 * `save_post_vl_webinar` listener at any priority would race ahead of
 * the metabox and read empty meta on the first save. Priority `20`
 * keeps the original "after meta savers" intent.
 *
 * @author Tymofii Synianskyi
 */
final class MeetingSynchronizerBootstrap {

	private MeetingSynchronizer $synchronizer;

	public function __construct( MeetingSynchronizer $synchronizer ) {
		$this->synchronizer = $synchronizer;
	}

	public function register(): void {
		add_action( 'save_post', [ $this->synchronizer, 'sync_on_save' ], 20, 3 );
		add_action( 'wp_trash_post', [ $this->synchronizer, 'sync_on_trash' ], 20, 1 );
		add_action( 'untrashed_post', [ $this->synchronizer, 'sync_on_untrash' ], 20, 1 );
	}
}
