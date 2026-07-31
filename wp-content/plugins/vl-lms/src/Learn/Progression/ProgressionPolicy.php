<?php

declare(strict_types=1);

namespace VL\LMS\Learn\Progression;

use VL\LMS\Learn\QuizStatusOverlay;

/**
 * Decides which curriculum stops are closed to a learner.
 *
 * Pure function over an ordered stop list plus the learner's quiz
 * standing and completed-stop set — no `WP_Post`, no `$wpdb`, no
 * `get_post_meta()`. Everything it needs was gathered by
 * {@see CurriculumOrder}, {@see QuizStatusOverlay} and
 * {@see CompletedSet} before it runs.
 *
 * Three independent rules produce locks:
 *
 * **The quiz frontier.** The first quiz in canonical order that is
 * flagged `_vl_quiz_blocks_progression` and has not been passed becomes
 * a frontier. Every stop *after* it is locked. Everything before it —
 * and the frontier quiz itself — stays open, so a learner can always
 * reach the thing that unblocks them, and can still revisit earlier
 * material. Only the *earliest* unpassed blocking quiz matters; later
 * ones are irrelevant until the learner gets past this one.
 *
 * **The sequential frontier.** On a course in sequential completion
 * mode, the first lesson or topic not yet marked complete is a second
 * frontier with the same shape: it stays open, everything after it
 * locks with `previous_incomplete` naming it. Two carve-outs keep the
 * rule honest: a lesson *with* topics is never a frontier candidate
 * ({@see \VL\LMS\Services\Progress\CompletionPropagator} only promotes
 * it once all its topics are complete, so treating it as a blocker
 * would deadlock its own first topic — it is still lockable like any
 * other stop), and quizzes never define this frontier — quiz gating is
 * opt-in per quiz via the flags above, and sequential mode must not
 * silently turn every quiz into a gate.
 *
 * When both frontiers exist, the *earlier* one wins outright and every
 * locked stop names it: the nearer blocker is the actionable one, and
 * any stop behind the later frontier is necessarily behind the earlier
 * one too. The indices can never tie — one frontier is a quiz stop,
 * the other a lesson/topic stop.
 *
 * **Course-wide prerequisites.** Independently of position, a quiz
 * flagged `_vl_quiz_requires_all_quizzes_passed` is locked while any
 * *other* non-final quiz in the course is unpassed. This is evaluated
 * regardless of the frontiers, so a final exam placed first in the tree
 * still gates correctly. A frontier outranks it: naming a specific
 * thing to go finish beats "finish everything else".
 *
 * Sessions are never locked. A cohort session is a calendar event with
 * its own join window ({@see \VL\LMS\Learn\Access\SessionAccessGate});
 * shutting a learner out of a scheduled Zoom call because they failed a
 * quiz strands them in a way no retry can fix. Sessions do *not* clear
 * the frontiers though — quizzes attached to a session, and every stop
 * after it, still lock. (Sequential mode is self-paced-only, so session
 * stops and the sequential frontier never actually meet — the exemption
 * is kept unconditional anyway so the rule cannot rot.)
 *
 * @author Tymofii Synianskyi
 */
final class ProgressionPolicy {

	/**
	 * @param list<CurriculumStop> $stops      Canonical order.
	 * @param bool                 $sequential Whether the course is in
	 *                                         sequential completion mode.
	 * @param CompletedSet|null    $completed  Completed stops; required
	 *                                         when `$sequential` is true.
	 */
	public function evaluate(
		array $stops,
		QuizStatusOverlay $quizzes,
		bool $sequential = false,
		?CompletedSet $completed = null
	): LockMap {
		$frontier = $this->find_frontier( $stops, $quizzes );

		if ( $sequential && null !== $completed ) {
			$sequential_frontier = $this->find_sequential_frontier( $stops, $completed );
			if ( null !== $sequential_frontier
				&& ( null === $frontier || $sequential_frontier['index'] < $frontier['index'] )
			) {
				$frontier = $sequential_frontier;
			}
		}

		$frontier_lock = null;
		if ( null !== $frontier ) {
			$frontier_lock = $frontier['stop']->is_quiz()
				? LockState::progression( $frontier['stop']->to_quiz_ref() )
				: LockState::previous_incomplete( $frontier['stop']->to_entity_ref() );
		}

		$locks = [];

		foreach ( $stops as $index => $stop ) {
			if ( $stop->is_session() ) {
				continue;
			}

			if ( null !== $frontier && null !== $frontier_lock && $index > $frontier['index'] ) {
				$locks[ $stop->key() ] = $frontier_lock;
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
	 * @return array{index: int, stop: CurriculumStop}|null
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
				'stop'  => $stop,
			];
		}
		return null;
	}

	/**
	 * Index and stop of the earliest incomplete lesson-without-topics or
	 * topic, or `null` when the learner has completed them all.
	 *
	 * @param list<CurriculumStop> $stops
	 *
	 * @return array{index: int, stop: CurriculumStop}|null
	 */
	private function find_sequential_frontier( array $stops, CompletedSet $completed ): ?array {
		foreach ( $stops as $index => $stop ) {
			$candidate = CurriculumStop::KIND_TOPIC === $stop->kind
				|| ( CurriculumStop::KIND_LESSON === $stop->kind && ! $stop->has_topics );
			if ( ! $candidate || $completed->has( $stop->key() ) ) {
				continue;
			}
			return [
				'index' => $index,
				'stop'  => $stop,
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
