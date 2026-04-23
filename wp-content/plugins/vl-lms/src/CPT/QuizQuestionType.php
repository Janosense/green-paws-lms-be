<?php

declare(strict_types=1);

namespace VL\LMS\CPT;

/**
 * Registers the `vl_quiz_question` custom post type and its meta schema.
 *
 * A question attaches under a quiz via `post_parent = vl_quiz ID`. The CPT
 * does not enforce the parent type; that rule lives in services/REST.
 *
 * Four question types are supported: `single_choice`, `multiple_choice`,
 * `true_false`, and `text`. Choice-type questions store their options in
 * `_vl_question_answers` as an array of `{ id, text, is_correct, explanation }`
 * objects. `true_false` reuses the same array with two entries ("True"/"False")
 * — the grading service treats it as a degenerate single-choice. `text`-type
 * questions store a correct answer in `_vl_question_correct_text` and a
 * match mode in `_vl_question_match_mode` (exact / case_insensitive / regex);
 * matching happens in the grading service, not here.
 *
 * Hidden from the main wp-admin menu (`show_in_menu: false`) — questions are
 * edited in the context of their parent quiz. The edit screen remains
 * reachable by direct URL because `show_ui` stays `true`. Hidden from `wp/v2`;
 * the Nuxt frontend reads questions through the `vl/v1/*` controllers.
 *
 * @author Tymofii Synianskyi
 */
final class QuizQuestionType extends AbstractCptRegistrar {

	protected function post_type(): string {
		return 'vl_quiz_question';
	}

	protected function singular_label(): string {
		return 'Question';
	}

	protected function plural_label(): string {
		return 'Questions';
	}

	protected function capability_type(): array {
		return [ 'vl_quiz_question', 'vl_quiz_questions' ];
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
			'_vl_question_type'         => [
				'type'              => 'string',
				'single'            => true,
				'default'           => 'single_choice',
				'show_in_rest'      => false,
				'sanitize_callback' => static fn ( mixed $v ): string => self::sanitize_question_type( $v ),
				'auth_callback'     => $auth,
				'description'       => 'Question kind — "single_choice", "multiple_choice", "true_false", or "text".',
			],
			'_vl_question_points'       => [
				'type'              => 'integer',
				'single'            => true,
				'default'           => 1,
				'show_in_rest'      => false,
				'sanitize_callback' => 'absint',
				'auth_callback'     => $auth,
				'description'       => 'Points awarded for a correct answer.',
			],
			'_vl_question_answers'      => [
				'type'              => 'array',
				'single'            => true,
				'default'           => [],
				'show_in_rest'      => false,
				'sanitize_callback' => static fn ( mixed $v ): array => self::sanitize_answers( $v ),
				'auth_callback'     => $auth,
				'description'       => 'Choice answers — list of { id, text, is_correct, explanation } objects.',
			],
			'_vl_question_correct_text' => [
				'type'              => 'string',
				'single'            => true,
				'default'           => '',
				'show_in_rest'      => false,
				'sanitize_callback' => 'sanitize_text_field',
				'auth_callback'     => $auth,
				'description'       => 'Correct answer for "text" type — exact string or regex pattern.',
			],
			'_vl_question_match_mode'   => [
				'type'              => 'string',
				'single'            => true,
				'default'           => 'exact',
				'show_in_rest'      => false,
				'sanitize_callback' => static fn ( mixed $v ): string => self::sanitize_match_mode( $v ),
				'auth_callback'     => $auth,
				'description'       => 'How _vl_question_correct_text is compared — "exact", "case_insensitive", or "regex".',
			],
			'_vl_question_explanation'  => [
				'type'              => 'string',
				'single'            => true,
				'default'           => '',
				'show_in_rest'      => false,
				'sanitize_callback' => 'wp_kses_post',
				'auth_callback'     => $auth,
				'description'       => 'Explanation shown to the student after answering.',
			],
			'_vl_question_media_url'    => [
				'type'              => 'string',
				'single'            => true,
				'default'           => '',
				'show_in_rest'      => false,
				'sanitize_callback' => 'esc_url_raw',
				'auth_callback'     => $auth,
				'description'       => 'Image or media URL shown with the question.',
			],
		];
	}

	/**
	 * Enum: "single_choice" | "multiple_choice" | "true_false" | "text".
	 * Unknown values fall back to "single_choice".
	 */
	private static function sanitize_question_type( mixed $value ): string {
		if ( is_string( $value ) && in_array( $value, [ 'single_choice', 'multiple_choice', 'true_false', 'text' ], true ) ) {
			return $value;
		}
		return 'single_choice';
	}

	/**
	 * Enum: "exact" | "case_insensitive" | "regex". Unknown values fall back to "exact".
	 */
	private static function sanitize_match_mode( mixed $value ): string {
		if ( is_string( $value ) && in_array( $value, [ 'exact', 'case_insensitive', 'regex' ], true ) ) {
			return $value;
		}
		return 'exact';
	}

	/**
	 * Normalize an answers list.
	 *
	 * Each element is coerced to `{ id, text, is_correct, explanation }`.
	 * Missing `id` is replaced with a fresh UUID. Elements that are both
	 * textless and not-correct are dropped (meaningless answer). Non-array
	 * elements are dropped; extra keys are stripped; the list is reindexed.
	 *
	 * @return list<array{id: string, text: string, is_correct: bool, explanation: string}>
	 */
	private static function sanitize_answers( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}

		$out = [];
		foreach ( $value as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$id = isset( $item['id'] ) && is_string( $item['id'] )
				? sanitize_text_field( $item['id'] )
				: '';
			if ( '' === $id ) {
				$id = wp_generate_uuid4();
			}

			$text = isset( $item['text'] ) && is_string( $item['text'] )
				? sanitize_text_field( $item['text'] )
				: '';

			$raw_is_correct = $item['is_correct'] ?? false;
			$is_correct     = in_array( $raw_is_correct, [ true, 1, '1', 'true', 'on', 'yes' ], true );

			if ( '' === $text && false === $is_correct ) {
				continue;
			}

			$explanation = isset( $item['explanation'] ) && is_string( $item['explanation'] )
				? sanitize_text_field( $item['explanation'] )
				: '';

			$out[] = [
				'id'          => $id,
				'text'        => $text,
				'is_correct'  => $is_correct,
				'explanation' => $explanation,
			];
		}

		return array_values( $out );
	}
}
