<?php

declare(strict_types=1);

namespace VL\LMS\CPT;

/**
 * Registers the `vl_topic` custom post type and its meta schema.
 *
 * A topic is a subdivision of a large lesson, attached via `post_parent` to
 * a `vl_lesson`. Each topic is an independently-tracked unit of progress —
 * the CPT carries its own video and duration, and the progress tables treat
 * it as a first-class item.
 *
 * Topics cannot host children (no nested quizzes, assignments, or further
 * topics). That rule is enforced in services and REST controllers, not at
 * the CPT level.
 *
 * Hidden from the main wp-admin menu (`show_in_menu: false`) — topics are
 * edited through their parent lesson. The edit screen is still reachable
 * via a direct URL because `show_ui` stays `true`. Hidden from `wp/v2`;
 * the Nuxt frontend reads topics through the `vl/v1/*` controllers.
 *
 * @author Tymofii Synianskyi
 */
final class TopicType extends AbstractCptRegistrar {

	protected function post_type(): string {
		return 'vl_topic';
	}

	protected function singular_label(): string {
		return 'Topic';
	}

	protected function plural_label(): string {
		return 'Topics';
	}

	protected function capability_type(): array {
		return [ 'vl_topic', 'vl_topics' ];
	}

	protected function supports(): array {
		return [ 'title', 'editor', 'custom-fields', 'page-attributes' ];
	}

	protected function menu_icon(): ?string {
		return null;
	}

	protected function hierarchical(): bool {
		return false;
	}

	protected function show_in_menu(): bool {
		return false;
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	protected function meta_fields(): array {
		$auth = static fn ( bool $allowed, string $meta_key, int $object_id ): bool =>
			current_user_can( 'edit_post', $object_id );

		return [
			'_vl_topic_video_url'        => [
				'type'              => 'string',
				'single'            => true,
				'default'           => '',
				'show_in_rest'      => false,
				'sanitize_callback' => 'esc_url_raw',
				'auth_callback'     => $auth,
				'description'       => 'Topic video URL.',
			],
			'_vl_topic_video_provider'   => [
				'type'              => 'string',
				'single'            => true,
				'default'           => 'file',
				'show_in_rest'      => false,
				'sanitize_callback' => static fn ( mixed $v ): string => self::sanitize_video_provider( $v ),
				'auth_callback'     => $auth,
				'description'       => 'Provider hint for the frontend player — "vimeo", "youtube", "file", or "embed".',
			],
			'_vl_topic_duration_seconds' => [
				'type'              => 'integer',
				'single'            => true,
				'default'           => 0,
				'show_in_rest'      => false,
				'sanitize_callback' => 'absint',
				'auth_callback'     => $auth,
				'description'       => 'Video duration in seconds.',
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
}
