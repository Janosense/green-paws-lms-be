<?php

declare(strict_types=1);

namespace VL\LMS\CPT;

/**
 * Registers the `vl_module` custom post type and its meta schema.
 *
 * Modules are an optional grouping layer inside self-paced courses: each
 * module attaches to a `vl_course` via `post_parent` and can itself host
 * lessons, quizzes, and assignments as its own children. Modules do not
 * nest (`hierarchical: false`) — ordering inside a course uses the default
 * `menu_order` field exposed through `page-attributes`.
 *
 * The CPT is hidden from `wp/v2` — the Nuxt frontend consumes modules via
 * the `vl/v1/*` controllers. The business rule that modules only belong
 * under self-paced courses is enforced in services and REST controllers,
 * not at the CPT level.
 *
 * @author Tymofii Synianskyi
 */
final class ModuleType extends AbstractCptRegistrar {

	protected function post_type(): string {
		return 'vl_module';
	}

	protected function singular_label(): string {
		return 'Module';
	}

	protected function plural_label(): string {
		return 'Modules';
	}

	protected function capability_type(): array {
		return [ 'vl_module', 'vl_modules' ];
	}

	protected function supports(): array {
		return [ 'title', 'editor', 'thumbnail', 'custom-fields', 'page-attributes' ];
	}

	protected function menu_icon(): ?string {
		return 'dashicons-portfolio';
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
			'_vl_module_intro_video_url'   => [
				'type'              => 'string',
				'single'            => true,
				'default'           => '',
				'show_in_rest'      => false,
				'sanitize_callback' => 'esc_url_raw',
				'auth_callback'     => $auth,
				'description'       => 'Optional introductory video URL for the module.',
			],
			'_vl_module_duration_minutes'  => [
				'type'              => 'integer',
				'single'            => true,
				'default'           => 0,
				'show_in_rest'      => false,
				'sanitize_callback' => 'absint',
				'auth_callback'     => $auth,
				'description'       => 'Estimated total duration across lessons, in minutes.',
			],
			'_vl_module_passing_threshold' => [
				'type'              => 'integer',
				'single'            => true,
				'default'           => 0,
				'show_in_rest'      => false,
				'sanitize_callback' => static fn ( mixed $v ): int => self::sanitize_percent( $v ),
				'auth_callback'     => $auth,
				'description'       => 'Completion threshold in percent (0 = no threshold).',
			],
		];
	}

	/**
	 * Clamp to [0, 100]. Non-numeric input collapses to 0.
	 */
	private static function sanitize_percent( mixed $value ): int {
		if ( ! is_numeric( $value ) ) {
			return 0;
		}
		$int = (int) $value;
		if ( $int < 0 ) {
			return 0;
		}
		if ( $int > 100 ) {
			return 100;
		}
		return $int;
	}
}
