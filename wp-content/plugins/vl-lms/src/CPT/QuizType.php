<?php

declare(strict_types=1);

namespace VL\LMS\CPT;

/**
 * Registers the `vl_quiz` custom post type and its meta schema.
 *
 * A quiz is a container for questions. It attaches under a lesson,
 * module, course, or session via `post_parent` — the CPT does not
 * restrict which parent type is allowed. That rule lives in services
 * and REST controllers, not here.
 *
 * Questions are not stored inline. They live in a separate `vl_quiz_question`
 * CPT; the `editor` support on this post type is for a quiz-level intro
 * shown to the student before they start.
 *
 * `_vl_quiz_is_final_exam` flags a quiz whose passing result is required
 * for the parent course's certificate. The CPT only stores the flag —
 * certificate issuance is handled by a later service.
 *
 * Time and attempt limits use `0` to mean "unlimited". The "show correct
 * answers" enum controls when the student sees the right answers:
 * `never`, `after_submit`, or `after_pass` (default `after_submit`).
 *
 * Hidden from `wp/v2` — the Nuxt frontend reads quizzes through the
 * `vl/v1/*` controllers.
 *
 * @author Tymofii Synianskyi
 */
final class QuizType extends AbstractCptRegistrar {

	protected function post_type(): string {
		return 'vl_quiz';
	}

	protected function singular_label(): string {
		return 'Тест';
	}

	protected function plural_label(): string {
		return 'Тести';
	}

	protected function capability_type(): array {
		return [ 'vl_quiz', 'vl_quizzes' ];
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
			'_vl_quiz_time_limit_seconds'          => [
				'type'              => 'integer',
				'single'            => true,
				'default'           => 0,
				'show_in_rest'      => false,
				'sanitize_callback' => 'absint',
				'auth_callback'     => $auth,
				'description'       => 'Time limit in seconds (0 = no limit).',
			],
			'_vl_quiz_max_attempts'                => [
				'type'              => 'integer',
				'single'            => true,
				'default'           => 0,
				'show_in_rest'      => false,
				'sanitize_callback' => 'absint',
				'auth_callback'     => $auth,
				'description'       => 'Maximum attempts per student (0 = unlimited).',
			],
			'_vl_quiz_passing_threshold'           => [
				'type'              => 'integer',
				'single'            => true,
				'default'           => 80,
				'show_in_rest'      => false,
				'sanitize_callback' => static fn ( mixed $v ): int => self::sanitize_percent( $v ),
				'auth_callback'     => $auth,
				'description'       => 'Passing percentage (0–100).',
			],
			'_vl_quiz_shuffle_questions'           => [
				'type'              => 'boolean',
				'single'            => true,
				'default'           => false,
				'show_in_rest'      => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
				'auth_callback'     => $auth,
				'description'       => 'Randomize question order per attempt.',
			],
			'_vl_quiz_shuffle_answers'             => [
				'type'              => 'boolean',
				'single'            => true,
				'default'           => false,
				'show_in_rest'      => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
				'auth_callback'     => $auth,
				'description'       => 'Randomize answer order per attempt.',
			],
			'_vl_quiz_show_correct_answers'        => [
				'type'              => 'string',
				'single'            => true,
				'default'           => 'after_submit',
				'show_in_rest'      => false,
				'sanitize_callback' => static fn ( mixed $v ): string => self::sanitize_show_correct_answers( $v ),
				'auth_callback'     => $auth,
				'description'       => 'When correct answers are revealed — "never", "after_submit", or "after_pass".',
			],
			'_vl_quiz_is_final_exam'               => [
				'type'              => 'boolean',
				'single'            => true,
				'default'           => false,
				'show_in_rest'      => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
				'auth_callback'     => $auth,
				'description'       => 'Passing is required for the parent course certificate.',
			],
			'_vl_quiz_blocks_progression'          => [
				'type'              => 'boolean',
				'single'            => true,
				'default'           => false,
				'show_in_rest'      => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
				'auth_callback'     => $auth,
				'description'       => 'Locks every later curriculum entity until this quiz is passed.',
			],
			'_vl_quiz_requires_all_quizzes_passed' => [
				'type'              => 'boolean',
				'single'            => true,
				'default'           => false,
				'show_in_rest'      => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
				'auth_callback'     => $auth,
				'description'       => 'Locks this quiz until every non-final quiz in the course is passed.',
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

	/**
	 * Enum: "never" | "after_submit" | "after_pass". Unknown values fall back to "after_submit".
	 */
	private static function sanitize_show_correct_answers( mixed $value ): string {
		if ( is_string( $value ) && in_array( $value, [ 'never', 'after_submit', 'after_pass' ], true ) ) {
			return $value;
		}
		return 'after_submit';
	}
}
