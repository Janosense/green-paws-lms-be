<?php

declare(strict_types=1);

namespace VL\LMS\Services\Zoom;

use VL\LMS\Services\Zoom\Sync\PostKind;
use WP_Post;
use WP_Query;

/**
 * Resolves a Zoom `meeting_id` to its owning `vl_session` or `vl_webinar`
 * post. The webhook handlers (Phase 7.2) rely on this single chokepoint
 * to decide which CPT they're updating.
 *
 * Concrete (not final) so unit tests can subclass and override
 * {@see self::run_query()} without booting WP_Query.
 *
 * @author Tymofii Synianskyi
 */
class PostLookup {

	public function find_by_meeting_id( string $meeting_id ): ?LookupResult {
		if ( '' === $meeting_id ) {
			return null;
		}

		$args = [
			'post_type'              => [ 'vl_session', 'vl_webinar' ],
			// Trash + every published-style status — webhooks should still
			// land on cancelled / draft / pending posts.
			'post_status'            => 'any',
			'posts_per_page'         => 1,
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Zoom meeting_id lookups are necessarily meta-keyed.
			'meta_query'             => [
				'relation' => 'OR',
				[
					'key'   => '_vl_session_zoom_meeting_id',
					'value' => $meeting_id,
				],
				[
					'key'   => '_vl_webinar_zoom_meeting_id',
					'value' => $meeting_id,
				],
			],
		];

		$posts = $this->run_query( $args );
		foreach ( $posts as $post ) {
			$kind = PostKind::from_post_type( (string) $post->post_type );
			if ( null !== $kind ) {
				return new LookupResult( $post, $kind );
			}
		}
		return null;
	}

	/**
	 * Indirected so unit tests can subclass and override without booting
	 * `WP_Query`.
	 *
	 * @param array<string, mixed> $args
	 * @return list<WP_Post>
	 */
	protected function run_query( array $args ): array {
		$query = new WP_Query( $args );
		$out   = [];
		foreach ( $query->posts as $post ) {
			if ( $post instanceof WP_Post ) {
				$out[] = $post;
			}
		}
		return $out;
	}
}
