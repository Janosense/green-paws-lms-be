<?php

declare(strict_types=1);

namespace VL\LMS\Cli;

use VL\LMS\Container;
use VL\LMS\Repositories\QuizAttemptRepository;
use WP_CLI;
use WP_Post;

/**
 * `wp vl-lms quiz` — learner-scoped quiz operations for support staff.
 *
 * Exists for one situation the admin UI cannot resolve: a quiz flagged
 * `_vl_quiz_blocks_progression` that also carries a finite
 * `_vl_quiz_max_attempts`. A learner who spends every attempt without
 * passing is stuck — the rest of the course stays locked, and nothing in
 * wp-admin can clear their attempts. Editing the quiz would change the
 * limit for the whole cohort, so the fix has to be per-learner.
 *
 * @author Tymofii Synianskyi
 */
final class QuizCommand {

	public function __construct( private readonly Container $container ) {
	}

	// NOTE: WP-CLI prints this docblock verbatim as the command's help text and
	// derives the subcommand name from the method name as-is — hence the
	// `@subcommand` tag below, without which this registers as the
	// un-idiomatic `wp vl-lms quiz reset_attempts`. Keep implementation notes
	// out here rather than in the docblock, or they surface in `--help`.

	/**
	 * Delete one learner's attempts on one quiz, letting them start over.
	 *
	 * ## OPTIONS
	 *
	 * --student=<student>
	 * : Learner's user ID, login, or email address. Named `--student` rather
	 * than `--user` because `--user` is a WP-CLI *global* flag ("run as this
	 * user") that WP-CLI consumes before the command ever sees it.
	 *
	 * --quiz=<quiz>
	 * : Quiz post ID or slug.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp vl-lms quiz reset-attempts --student=42 --quiz=118
	 *     wp vl-lms quiz reset-attempts --student=learner@example.com --quiz=modul-1-test --yes
	 *
	 * @subcommand reset-attempts
	 *
	 * @param list<string>         $args       Positional args (unused).
	 * @param array<string, mixed> $assoc_args Associative args.
	 */
	public function reset_attempts( array $args, array $assoc_args ): void {
		unset( $args );

		$user = $this->resolve_user( (string) ( $assoc_args['student'] ?? '' ) );
		$quiz = $this->resolve_quiz( (string) ( $assoc_args['quiz'] ?? '' ) );

		$repository = $this->container->get( QuizAttemptRepository::class );
		if ( ! $repository instanceof QuizAttemptRepository ) {
			WP_CLI::error( __( 'Quiz attempt repository is unavailable.', 'vl-lms' ) );
			return;
		}

		$existing = $repository->count_for_user_in_quiz( $user->ID, (int) $quiz->ID );
		if ( 0 === $existing ) {
			WP_CLI::success(
				sprintf(
					/* translators: 1: user login, 2: quiz title. */
					__( '%1$s has no attempts on "%2$s" — nothing to reset.', 'vl-lms' ),
					$user->user_login,
					$quiz->post_title
				)
			);
			return;
		}

		WP_CLI::confirm(
			sprintf(
				/* translators: 1: attempt count, 2: user login, 3: quiz title. */
				__( 'Delete %1$d attempt(s) by %2$s on "%3$s"? This cannot be undone.', 'vl-lms' ),
				$existing,
				$user->user_login,
				$quiz->post_title
			),
			$assoc_args
		);

		$deleted = $repository->delete_for_user_in_quiz( $user->ID, (int) $quiz->ID );

		WP_CLI::success(
			sprintf(
				/* translators: %d: number of deleted attempts. */
				_n( 'Deleted %d attempt.', 'Deleted %d attempts.', $deleted, 'vl-lms' ),
				$deleted
			)
		);
	}

	/**
	 * Accepts an ID, login, or email — the same flexibility core's own
	 * `wp user` commands offer, since support staff rarely have the ID.
	 */
	private function resolve_user( string $identifier ): \WP_User {
		if ( '' === $identifier ) {
			WP_CLI::error( __( '--student is required.', 'vl-lms' ) );
		}

		if ( is_numeric( $identifier ) ) {
			$user = get_user_by( 'id', (int) $identifier );
		} else {
			$user = get_user_by( 'login', $identifier );
			if ( ! $user instanceof \WP_User ) {
				$user = get_user_by( 'email', $identifier );
			}
		}

		if ( ! $user instanceof \WP_User ) {
			WP_CLI::error(
				sprintf(
					/* translators: %s: the supplied identifier. */
					__( 'No user matched "%s".', 'vl-lms' ),
					$identifier
				)
			);
		}

		return $user;
	}

	private function resolve_quiz( string $identifier ): WP_Post {
		if ( '' === $identifier ) {
			WP_CLI::error( __( '--quiz is required.', 'vl-lms' ) );
		}

		$quiz = is_numeric( $identifier )
			? get_post( (int) $identifier )
			: $this->find_quiz_by_slug( $identifier );

		if ( ! $quiz instanceof WP_Post || 'vl_quiz' !== $quiz->post_type ) {
			WP_CLI::error(
				sprintf(
					/* translators: %s: the supplied identifier. */
					__( 'No quiz matched "%s".', 'vl-lms' ),
					$identifier
				)
			);
		}

		return $quiz;
	}

	/**
	 * Any post status — a support reset may well target a quiz that has
	 * since been unpublished.
	 */
	private function find_quiz_by_slug( string $slug ): ?WP_Post {
		$posts = get_posts(
			[
				'post_type'              => 'vl_quiz',
				'name'                   => $slug,
				'post_status'            => 'any',
				'posts_per_page'         => 1,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			]
		);

		return is_array( $posts ) && [] !== $posts && $posts[0] instanceof WP_Post ? $posts[0] : null;
	}
}
