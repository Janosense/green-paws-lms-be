<?php

declare(strict_types=1);

namespace VL\LMS\Orders;

use VL\LMS\Domain\Order\PurchasableEntityType;
use WP_Post;

/**
 * Resolves a slug to its underlying course / webinar `WP_Post`.
 *
 * Returns `null` for missing posts and for posts whose `post_status` is
 * not `publish` — drafts and trashed entries are treated as not-found
 * from an outside-of-WP-admin perspective.
 *
 * @author Tymofii Synianskyi
 */
class PurchasableLookup {

	public function find( PurchasableEntityType $type, string $slug ): ?WP_Post {
		if ( '' === $slug ) {
			return null;
		}

		$post = $this->find_post( $slug, $type->wp_post_type() );
		if ( ! $post instanceof WP_Post ) {
			return null;
		}

		if ( 'publish' !== $post->post_status ) {
			return null;
		}

		return $post;
	}

	/**
	 * Indirected so tests can subclass and override without round-tripping
	 * through `get_page_by_path()`.
	 */
	protected function find_post( string $slug, string $post_type ): ?WP_Post {
		$post = get_page_by_path( $slug, OBJECT, $post_type );
		return $post instanceof WP_Post ? $post : null;
	}
}
