<?php

declare(strict_types=1);

namespace VL\LMS\User;

/**
 * Registers public profile user meta for instructor users.
 *
 * Two keys live here:
 *
 * - `vl_instructor_avatar_id` — WP attachment ID for the avatar shown on
 *   instructor cards across the catalog and inside course / webinar
 *   landings. `0` is the sentinel for "no custom avatar — fall back to
 *   Gravatar".
 * - `vl_instructor_bio` — public HTML bio rendered on course / webinar
 *   landing pages, kses-filtered on write so author-supplied HTML is
 *   safe to embed in the SSR output. Empty string = no bio.
 *
 * Headless: kept out of `wp/v2` because the Nuxt frontend reads these
 * through `vl/v1/*`. The `auth_callback` defers to
 * `current_user_can('edit_user', $object_id)`, which implicitly allows
 * users to edit their own profile and admins to edit any user's profile
 * without us having to special-case roles.
 *
 * @author Tymofii Synianskyi
 */
final class InstructorProfileMetaRegistrar {

	public const AVATAR_META_KEY = 'vl_instructor_avatar_id';
	public const BIO_META_KEY    = 'vl_instructor_bio';

	public function register(): void {
		$auth = static fn ( bool $allowed, string $meta_key, int $object_id ): bool =>
			current_user_can( 'edit_user', $object_id );

		register_meta(
			'user',
			self::AVATAR_META_KEY,
			[
				'type'              => 'integer',
				'single'            => true,
				'default'           => 0,
				'show_in_rest'      => false,
				'sanitize_callback' => 'absint',
				'auth_callback'     => $auth,
				'description'       => 'WP attachment ID for the user\'s instructor-card avatar. 0 = use Gravatar fallback.',
			]
		);

		register_meta(
			'user',
			self::BIO_META_KEY,
			[
				'type'              => 'string',
				'single'            => true,
				'default'           => '',
				'show_in_rest'      => false,
				'sanitize_callback' => 'wp_kses_post',
				'auth_callback'     => $auth,
				'description'       => 'Public instructor bio (HTML, kses-filtered). Empty string = no bio.',
			]
		);
	}
}
