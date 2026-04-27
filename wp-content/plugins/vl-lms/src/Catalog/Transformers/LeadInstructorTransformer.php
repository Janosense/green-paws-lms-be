<?php

declare(strict_types=1);

namespace VL\LMS\Catalog\Transformers;

use VL\LMS\Domain\CourseInstructor\CourseInstructor;
use WP_User;

/**
 * Builds the `lead_instructor` payload for a catalog card.
 *
 * Resolves a {@see CourseInstructor} row to a `{ id, display_name, avatar }`
 * shape. The avatar source is the user's `vl_instructor_avatar_id` meta
 * (medium WP size) when it points at a real attachment; otherwise we fall
 * back to the user's Gravatar at 96px so the frontend always receives a
 * non-null avatar URL.
 *
 * @author Tymofii Synianskyi
 */
final class LeadInstructorTransformer {

	private const AVATAR_SIZE = 96;

	/**
	 * @return array{id: int, display_name: string, avatar: array{url: string, size: int}}|null
	 */
	public function transform( ?CourseInstructor $lead ): ?array {
		if ( null === $lead ) {
			return null;
		}

		$user = get_user_by( 'id', $lead->user_id );
		if ( ! $user instanceof WP_User ) {
			return null;
		}

		return [
			'id'           => (int) $user->ID,
			'display_name' => (string) $user->display_name,
			'avatar'       => $this->resolve_avatar( $user ),
		];
	}

	/**
	 * @return array{url: string, size: int}
	 */
	private function resolve_avatar( WP_User $user ): array {
		$attachment_id = (int) get_user_meta( (int) $user->ID, 'vl_instructor_avatar_id', true );
		if ( $attachment_id > 0 ) {
			$src = wp_get_attachment_image_src( $attachment_id, 'medium' );
			if ( is_array( $src ) && '' !== (string) $src[0] ) {
				return [
					'url'  => (string) $src[0],
					'size' => self::AVATAR_SIZE,
				];
			}
		}

		return [
			'url'  => (string) get_avatar_url( $user->ID, [ 'size' => self::AVATAR_SIZE ] ),
			'size' => self::AVATAR_SIZE,
		];
	}
}
