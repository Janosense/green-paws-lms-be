<?php

declare(strict_types=1);

namespace VL\LMS\Learn\Progression;

use VL\LMS\Learn\QuizStatusOverlay;

/**
 * Decides which curriculum stops are closed to a learner.
 *
 * Pure function over an ordered stop list plus the learner's quiz
 * standing — no `WP_Post`, no `$wpdb`, no `get_post_meta()`. Everything
 * it needs was gathered by {@see CurriculumOrder} and
 * {@see QuizStatusOverlay} before it runs.
 *
 * Two independent rules produce locks:
 *
 * **The frontier.** The first quiz in canonical order that is flagged
 * `_vl_quiz_blocks_progression` and has not been passed becomes the
 * frontier. Every stop *after* it is locked. Everything before it — and
 * the frontier quiz itself — stays open, so a learner can always reach
 * the thing that unblocks them, and can still revisit earlier material.
 * Only the *earliest* unpassed blocking quiz matters; later ones are
 * irrelevant until the learner gets past this one.
 *
 * **Course-wide prerequisites.** Independently of position, a quiz
 * flagged `_vl_quiz_requires_all_quizzes_passed` is locked while any
 * *other* non-final quiz in the course is unpassed. This is evaluated
 * regardless of the frontier, so a final exam placed first in the tree
 * still gates correctly.
 *
 * When both apply to the same quiz the frontier wins: it is the nearer,
 * more actionable blocker, and naming a specific quiz to go pass beats
 * "finish everything else".
 *
 * Sessions are never locked. A cohort session is a calendar event with
 * its own join window ({@see \VL\LMS\Learn\Access\SessionAccessGate});
 * shutting a learner out of a scheduled Zoom call because they failed a
 * quiz strands them in a way no retry can fix. Sessions do *not* clear
 * the frontier though — quizzes attached to a session, and every stop
 * after it, still lock.
 *
 * @author Tymofii Synianskyi
 */
final class ProgressionPolicy {

	/**
	 * @param list<CurriculumStop> $stops Canonical order.
	 */
	public function evaluate( array $stops, QuizStatusOverlay $quizzes ): LockMap {
		$frontier = $this->find_frontier( $stops, $quizzes );
		$locks    = [];

		foreach ( $stops as $index => $stop ) {
			if ( $stop->is_session() ) {
				continue;
			}

			if ( null !== $frontier && $index > $frontier['index'] ) {
				$locks[ $stop->key() ] = LockState::progression( $frontier['quiz']->to_quiz_ref() );
				continue;
			}

			$remaining = $this->unmet_course_prerequisites( $stop, $stops, $quizzes );
			if ( $remaining > 0 ) {
				$locks[ $stop->key() ] = LockState::course_quizzes_incomplete( $remaining );
			}
		}

		return [] === $locks ? LockMap::empty() : LockMap::fromArray( $locks );
	}

	/**
	 * Index and stop of the earliest unpassed blocking quiz, or `null`
	 * when the learner has cleared every gate (or none exists).
	 *
	 * @param list<CurriculumStop> $stops
	 *
	 * @return array{index: int, quiz: CurriculumStop}|null
	 */
	private function find_frontier( array $stops, QuizStatusOverlay $quizzes ): ?array {
		foreach ( $stops as $index => $stop ) {
			if ( ! $stop->is_quiz() || ! $stop->blocks_progression ) {
				continue;
			}
			if ( 'passed' === $quizzes->status( $stop->id ) ) {
				continue;
			}
			return [
				'index' => $index,
				'quiz'  => $stop,
			];
		}
		return null;
	}

	/**
	 * How many of the course's other non-final quizzes this stop is still
	 * waiting on. `0` when the rule does not apply to this stop.
	 *
	 * Final exams are excluded from the prerequisite set so two final
	 * exams both carrying the flag cannot deadlock each other, and the
	 * quiz itself is excluded so the rule is never self-referential. An
	 * empty prerequisite set yields `0` — an empty universal quantifier
	 * is satisfied, so the flag is inert on a course with no other
	 * quizzes rather than making that quiz unreachable.
	 *
	 * @param list<CurriculumStop> $stops
	 */
	private function unmet_course_prerequisites(
		CurriculumStop $stop,
		array $stops,
		QuizStatusOverlay $quizzes
	): int {
		if ( ! $stop->is_quiz() || ! $stop->requires_all_quizzes ) {
			return 0;
		}

		$missing = 0;
		foreach ( $stops as $candidate ) {
			if ( ! $candidate->is_quiz() || $candidate->is_final_exam ) {
				continue;
			}
			if ( $candidate->id === $stop->id ) {
				continue;
			}
			if ( 'passed' !== $quizzes->status( $candidate->id ) ) {
				++$missing;
			}
		}

		return $missing;
	}
}
