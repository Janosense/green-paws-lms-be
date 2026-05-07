<?php

declare(strict_types=1);

namespace VL\LMS\Slug;

/**
 * Hooks into WordPress's post-save pipeline to transliterate Cyrillic slugs
 * for two tiers of post types:
 *
 * 1. **Inner-course CPTs** (`vl_module`, `vl_lesson`, `vl_topic`, `vl_quiz`,
 *    `vl_quiz_question`, `vl_session`, `vl_assignment`) — transliterated on
 *    *every* save. These are auth-gated and never indexed; their URLs only
 *    appear in the lesson-player surface, so silently rewriting them on
 *    rename is purely an ergonomics fix with no SEO blast radius.
 *
 * 2. **Catalog CPTs** (`vl_course`, `vl_webinar`) — transliterated only at
 *    *creation time*. The catalog detail route (`/vl/v1/catalog/courses/{slug}`)
 *    cannot resolve a Cyrillic slug if the route regex restricts to ASCII,
 *    so a brand-new course derived from a Ukrainian title would 404 on the
 *    public detail endpoint. Once the post has been saved non-`auto-draft`
 *    at least once, the slug becomes editor territory and we never silently
 *    rewrite it — that protects URLs that may already be indexed by search
 *    engines or shared externally. Editors can still rename catalog slugs
 *    by hand via the slug field in wp-admin; this filter only intervenes
 *    when WordPress would otherwise persist a Cyrillic slug for the very
 *    first save.
 *
 * Why `wp_insert_post_data` and not `sanitize_title`: the latter has no
 * post-type context, so a global filter would also rewrite category slugs,
 * tag slugs, attachment names, etc. — silently wide blast radius. The
 * former runs once per save with the post type in hand, so the scope is
 * exact.
 *
 * After transliteration we re-call `wp_unique_post_slug` because the
 * de-Cyrillised string may now collide with a sibling under the same
 * parent (`gp-c1-m1-l1-камери-серця…` and `gp-c1-m1-l1-кaмери-серця…`
 * could both transliterate to `gp-c1-m1-l1-kameri-sertsia` if titles drift
 * during editing). Letting WP own uniqueness keeps the existing
 * `-2`/`-3` suffix policy intact.
 *
 * @author Tymofii Synianskyi
 */
final class SlugTransliterationListener {

	/**
	 * Inner-course CPTs — always transliterated on every save.
	 *
	 * @var list<string>
	 */
	private const ALWAYS_POST_TYPES = [
		'vl_module',
		'vl_lesson',
		'vl_topic',
		'vl_quiz',
		'vl_quiz_question',
		'vl_session',
		'vl_assignment',
	];

	/**
	 * Catalog CPTs — transliterated only on creation (no DB row yet, or
	 * existing row still in `auto-draft`). See class-level docblock for
	 * the SEO rationale.
	 *
	 * @var list<string>
	 */
	private const CREATE_ONLY_POST_TYPES = [
		'vl_course',
		'vl_webinar',
	];

	public function __construct(
		private CyrillicTransliterator $transliterator
	) {
	}

	public function register_hooks(): void {
		add_filter( 'wp_insert_post_data', [ $this, 'filter_insert_data' ], 10, 2 );
	}

	/**
	 * @param array<string, mixed> $data    Sanitised data destined for `wp_posts`.
	 * @param array<string, mixed> $postarr Original data passed to `wp_insert_post()`.
	 * @return array<string, mixed>
	 */
	public function filter_insert_data( array $data, array $postarr ): array {
		$post_type = isset( $data['post_type'] ) && is_string( $data['post_type'] ) ? $data['post_type'] : '';
		if ( '' === $post_type ) {
			return $data;
		}

		$post_id = isset( $postarr['ID'] ) ? (int) $postarr['ID'] : 0;

		$applies = in_array( $post_type, self::ALWAYS_POST_TYPES, true )
			|| (
				in_array( $post_type, self::CREATE_ONLY_POST_TYPES, true )
				&& $this->is_creation_save( $post_id )
			);

		if ( ! $applies ) {
			return $data;
		}

		$current_slug = isset( $data['post_name'] ) && is_string( $data['post_name'] ) ? $data['post_name'] : '';
		if ( '' === $current_slug ) {
			return $data;
		}

		$transliterated = $this->transliterator->slug( $current_slug );
		if ( '' === $transliterated || $transliterated === $current_slug ) {
			return $data;
		}

		$post_status = isset( $data['post_status'] ) && is_string( $data['post_status'] ) ? $data['post_status'] : 'draft';
		$post_parent = isset( $data['post_parent'] ) ? (int) $data['post_parent'] : 0;

		$data['post_name'] = wp_unique_post_slug(
			$transliterated,
			$post_id,
			$post_status,
			$post_type,
			$post_parent
		);

		return $data;
	}

	/**
	 * A "creation save" means no DB row yet (`ID === 0`) or the existing
	 * row is still in `auto-draft`. Both indicate the slug being processed
	 * was derived from the title by `sanitize_title` for the first time,
	 * not editor-typed against an already-live post.
	 */
	private function is_creation_save( int $post_id ): bool {
		if ( 0 === $post_id ) {
			return true;
		}

		return 'auto-draft' === get_post_status( $post_id );
	}
}
