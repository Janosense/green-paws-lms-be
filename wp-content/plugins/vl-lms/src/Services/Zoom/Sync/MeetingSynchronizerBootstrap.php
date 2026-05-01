<?php

declare(strict_types=1);

namespace VL\LMS\Services\Zoom\Sync;

/**
 * Wires {@see MeetingSynchronizer}'s adapter methods to the four WP
 * post-lifecycle hooks: `save_post_vl_session`, `save_post_vl_webinar`,
 * `wp_trash_post`, and `untrashed_post`.
 *
 * Lives separately from the orchestrator so unit tests can exercise
 * `sync()` without poking at WP's hook system.
 *
 * Priority `20` ensures stock meta savers (Custom Fields metabox at
 * priority `10`) have already written the new meta values by the time
 * the synchronizer reads them.
 *
 * @author Tymofii Synianskyi
 */
final class MeetingSynchronizerBootstrap {

	private MeetingSynchronizer $synchronizer;

	public function __construct( MeetingSynchronizer $synchronizer ) {
		$this->synchronizer = $synchronizer;
	}

	public function register(): void {
		add_action( 'save_post_vl_session', [ $this->synchronizer, 'sync_on_save' ], 20, 3 );
		add_action( 'save_post_vl_webinar', [ $this->synchronizer, 'sync_on_save' ], 20, 3 );
		add_action( 'wp_trash_post', [ $this->synchronizer, 'sync_on_trash' ], 20, 1 );
		add_action( 'untrashed_post', [ $this->synchronizer, 'sync_on_untrash' ], 20, 1 );
	}
}
