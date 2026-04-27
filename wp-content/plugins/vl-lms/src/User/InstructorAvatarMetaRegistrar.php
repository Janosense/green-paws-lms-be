<?php

declare(strict_types=1);

namespace VL\LMS\User;

/**
 * Registers the `vl_instructor_avatar_id` user meta key.
 *
 * Stores a WP attachment ID used as the instructor's avatar on instructor
 * cards across the catalog and inside course landings. `0` is the sentinel
 * for "no custom avatar — fall back to Gravatar". Headless: kept out of
 * `wp/v2` because the Nuxt frontend reads it through `vl/v1/*`.
 *
 * The `auth_callback` is `current_user_can('edit_user', $object_id)`, which
 * implicitly allows users to edit their own avatar and admins to edit any
 * user's avatar without us having to special-case roles.
 *
 * @author Tymofii Synianskyi
 */
final class InstructorAvatarMetaRegistrar {

	public const META_KEY = 'vl_instructor_avatar_id';

	public function register(): void {
		register_meta(
			'user',
			self::META_KEY,
			[
				'type'              => 'integer',
				'single'            => true,
				'default'           => 0,
				'show_in_rest'      => false,
				'sanitize_callback' => 'absint',
				'auth_callback'     => static fn ( bool $allowed, string $meta_key, int $object_id ): bool =>
					current_user_can( 'edit_user', $object_id ),
				'description'       => 'WP attachment ID for the user\'s instructor-card avatar. 0 = use Gravatar fallback.',
			]
		);
	}
}
