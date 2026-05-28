<?php

declare(strict_types=1);

namespace VL\LMS\CPT;

/**
 * Registers the `vl_lesson` custom post type and its meta schema.
 *
 * Lessons are the content unit inside self-paced courses. A lesson attaches
 * to either a `vl_course` (small, module-less courses) or a `vl_module`
 * (courses using the module layer) via `post_parent` — the CPT itself
 * imposes no constraint on which; parent-shape rules live in services.
 *
 * A lesson is either a single video (`_vl_lesson_video_url` + provider) or
 * a container for child `vl_topic` posts. Both representations are legal
 * at the CPT level; the admin UX may warn when both are configured.
 *
 * Hidden from `wp/v2` — the Nuxt frontend reads lessons through the
 * `vl/v1/*` controllers.
 *
 * @author Tymofii Synianskyi
 */
final class LessonType extends AbstractCptRegistrar {

	protected function post_type(): string {
		return 'vl_lesson';
	}

	protected function singular_label(): string {
		return 'Урок';
	}

	protected function plural_label(): string {
		return 'Уроки';
	}

	protected function capability_type(): array {
		return [ 'vl_lesson', 'vl_lessons' ];
	}

	protected function supports(): array {
		return [ 'title', 'editor', 'thumbnail', 'custom-fields', 'page-attributes' ];
	}

	protected function menu_icon(): string {
		return 'dashicons-video-alt3';
	}

	protected function hierarchical(): bool {
		return false;
	}

	protected function show_in_menu(): bool {
		return true;
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	protected function meta_fields(): array {
		$auth = static fn ( bool $allowed, string $meta_key, int $object_id ): bool =>
			current_user_can( 'edit_post', $object_id );

		return [
			'_vl_lesson_video_url'           => [
				'type'              => 'string',
				'single'            => true,
				'default'           => '',
				'show_in_rest'      => false,
				'sanitize_callback' => 'esc_url_raw',
				'auth_callback'     => $auth,
				'description'       => 'Main video URL for the lesson.',
			],
			'_vl_lesson_video_provider'      => [
				'type'              => 'string',
				'single'            => true,
				'default'           => 'file',
				'show_in_rest'      => false,
				'sanitize_callback' => static fn ( mixed $v ): string => self::sanitize_video_provider( $v ),
				'auth_callback'     => $auth,
				'description'       => 'Provider hint for the frontend player — "vimeo", "youtube", "file", or "embed".',
			],
			'_vl_lesson_duration_seconds'    => [
				'type'              => 'integer',
				'single'            => true,
				'default'           => 0,
				'show_in_rest'      => false,
				'sanitize_callback' => 'absint',
				'auth_callback'     => $auth,
				'description'       => 'Video duration in seconds.',
			],
			'_vl_lesson_is_preview'          => [
				'type'              => 'boolean',
				'single'            => true,
				'default'           => false,
				'show_in_rest'      => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
				'auth_callback'     => $auth,
				'description'       => 'Whether the lesson is accessible without enrollment.',
			],
			'_vl_lesson_attachments'         => [
				'type'              => 'array',
				'single'            => true,
				'default'           => [],
				'show_in_rest'      => false,
				'sanitize_callback' => static fn ( mixed $v ): array => self::sanitize_attachments( $v ),
				'auth_callback'     => $auth,
				'description'       => 'Downloadable files — list of { url, name, size } objects.',
			],
			'_vl_lesson_requires_completion' => [
				'type'              => 'boolean',
				'single'            => true,
				'default'           => true,
				'show_in_rest'      => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
				'auth_callback'     => $auth,
				'description'       => 'Whether completing this lesson counts toward course progress.',
			],
		];
	}

	/**
	 * Enum: "vimeo" | "youtube" | "file" | "embed". Unknown values fall back to "file".
	 */
	private static function sanitize_video_provider( mixed $value ): string {
		if ( is_string( $value ) && in_array( $value, [ 'vimeo', 'youtube', 'file', 'embed' ], true ) ) {
			return $value;
		}
		return 'file';
	}

	/**
	 * Normalize an attachments list.
	 *
	 * Each element is coerced to `{ url, name, size }`. Elements whose URL
	 * is empty after sanitization are dropped; extra keys are stripped.
	 * Non-array input collapses to an empty list.
	 *
	 * @return list<array{url: string, name: string, size: int}>
	 */
	private static function sanitize_attachments( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}

		$out = [];
		foreach ( $value as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$url = isset( $item['url'] ) && is_string( $item['url'] )
				? esc_url_raw( $item['url'] )
				: '';
			if ( '' === $url ) {
				continue;
			}

			$name = isset( $item['name'] ) && is_string( $item['name'] )
				? sanitize_text_field( $item['name'] )
				: '';
			$size = isset( $item['size'] ) ? absint( $item['size'] ) : 0;

			$out[] = [
				'url'  => $url,
				'name' => $name,
				'size' => $size,
			];
		}

		return $out;
	}
}
