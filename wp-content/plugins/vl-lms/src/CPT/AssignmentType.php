<?php

declare(strict_types=1);

namespace VL\LMS\CPT;

/**
 * Registers the `vl_assignment` custom post type and its meta schema.
 *
 * An assignment is a homework-style task with manual grading by the
 * instructor. It attaches under a lesson, module, course, or session via
 * `post_parent` — flexible parent, like `vl_quiz`. The CPT does not
 * restrict the parent type; that rule lives in services/REST.
 *
 * Submissions (text + file + grade + feedback) are not stored here.
 * They live in the Phase 1.5 `vl_submissions` table. This CPT defines
 * only the assignment itself: what to submit, scoring bounds, a
 * relative deadline, and a grading rubric.
 *
 * `submission_type` selects between text, file, or both. Two booleans
 * then say whether each field is required. When the submission type
 * does not include one of them, the submission service ignores the
 * corresponding "required" flag — no cross-field validation at the
 * CPT level.
 *
 * Scoring: `_vl_assignment_max_score` is the ceiling, `_vl_assignment_passing_score`
 * is the pass bar. Each is sanitized with `absint` independently —
 * the admin UX enforces `passing <= max` in a later task.
 *
 * `_vl_assignment_due_days_after_enrollment` is relative: the service
 * layer computes the absolute due date from each student's enrollment
 * timestamp. `0` means no deadline.
 *
 * `_vl_assignment_rubric` is instructor-only — sanitized with
 * `wp_kses_post` to allow pasted HTML formatting.
 *
 * Hidden from `wp/v2` — the Nuxt frontend reads assignments through
 * the `vl/v1/*` controllers.
 *
 * @author Tymofii Synianskyi
 */
final class AssignmentType extends AbstractCptRegistrar {

	protected function post_type(): string {
		return 'vl_assignment';
	}

	protected function singular_label(): string {
		return 'Завдання';
	}

	protected function plural_label(): string {
		return 'Завдання';
	}

	protected function capability_type(): array {
		return [ 'vl_assignment', 'vl_assignments' ];
	}

	protected function supports(): array {
		return [ 'title', 'editor', 'custom-fields', 'page-attributes' ];
	}

	protected function menu_icon(): string {
		return 'dashicons-clipboard';
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
			'_vl_assignment_max_score'                 => [
				'type'              => 'integer',
				'single'            => true,
				'default'           => 100,
				'show_in_rest'      => false,
				'sanitize_callback' => 'absint',
				'auth_callback'     => $auth,
				'description'       => 'Maximum achievable score.',
			],
			'_vl_assignment_passing_score'             => [
				'type'              => 'integer',
				'single'            => true,
				'default'           => 60,
				'show_in_rest'      => false,
				'sanitize_callback' => 'absint',
				'auth_callback'     => $auth,
				'description'       => 'Score required to pass.',
			],
			'_vl_assignment_submission_type'           => [
				'type'              => 'string',
				'single'            => true,
				'default'           => 'both',
				'show_in_rest'      => false,
				'sanitize_callback' => static fn ( mixed $v ): string => self::sanitize_submission_type( $v ),
				'auth_callback'     => $auth,
				'description'       => 'What the student may submit — "text", "file", or "both".',
			],
			'_vl_assignment_text_required'             => [
				'type'              => 'boolean',
				'single'            => true,
				'default'           => true,
				'show_in_rest'      => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
				'auth_callback'     => $auth,
				'description'       => 'Whether the text field is mandatory.',
			],
			'_vl_assignment_file_required'             => [
				'type'              => 'boolean',
				'single'            => true,
				'default'           => false,
				'show_in_rest'      => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
				'auth_callback'     => $auth,
				'description'       => 'Whether a file upload is mandatory.',
			],
			'_vl_assignment_due_days_after_enrollment' => [
				'type'              => 'integer',
				'single'            => true,
				'default'           => 0,
				'show_in_rest'      => false,
				'sanitize_callback' => 'absint',
				'auth_callback'     => $auth,
				'description'       => 'Deadline in days after enrollment (0 = no deadline).',
			],
			'_vl_assignment_rubric'                    => [
				'type'              => 'string',
				'single'            => true,
				'default'           => '',
				'show_in_rest'      => false,
				'sanitize_callback' => 'wp_kses_post',
				'auth_callback'     => $auth,
				'description'       => 'Grading criteria shown only to the instructor.',
			],
		];
	}

	/**
	 * Enum: "text" | "file" | "both". Unknown values fall back to "both".
	 */
	private static function sanitize_submission_type( mixed $value ): string {
		if ( is_string( $value ) && in_array( $value, [ 'text', 'file', 'both' ], true ) ) {
			return $value;
		}
		return 'both';
	}
}
