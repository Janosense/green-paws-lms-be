<?php

declare(strict_types=1);

namespace VL\LMS\Slug;

/**
 * Hooks into WordPress's post-save pipeline to transliterate Cyrillic slugs
 * for the inner-course CPTs (lessons, topics, modules, quizzes, sessions,
 * assignments, quiz questions).
 *
 * Why these and not `vl_course` / `vl_webinar`: the catalog post types are
 * SEO-relevant — their slugs land in shareable public URLs and may already
 * be indexed by search engines. We don't auto-rewrite those without an
 * editor's say-so. The inner-course CPTs are auth-gated, never indexed, and
 * their URLs only appear in the lesson-player surface, so transliteration
 * is purely an ergonomics fix.
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
	 * Inner-course CPTs only — see class-level docblock for the rationale.
	 *
	 * @var list<string>
	 */
	private const TARGETED_POST_TYPES = [
		'vl_module',
		'vl_lesson',
		'vl_topic',
		'vl_quiz',
		'vl_quiz_question',
		'vl_session',
		'vl_assignment',
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
		if ( ! in_array( $post_type, self::TARGETED_POST_TYPES, true ) ) {
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

		$post_id     = isset( $postarr['ID'] ) ? (int) $postarr['ID'] : 0;
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
}
