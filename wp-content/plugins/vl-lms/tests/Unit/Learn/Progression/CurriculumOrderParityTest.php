<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Learn\Progression;

use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Learn\NextEntityResolver;
use VL\LMS\Learn\Progression\CurriculumOrder;
use VL\LMS\Learn\Progression\CurriculumStop;
use WP_Post;

/**
 * Guard rail for the canonical-order invariant.
 *
 * `CLAUDE.md` flags the curriculum walk as a dual-maintenance hazard: it
 * is transcribed in {@see NextEntityResolver} on the backend and in
 * `useLearnNavigation.flattenCurriculum` on the frontend, and if they
 * diverge, "Continue" lands somewhere "Next" never reaches. Progression
 * locking adds a third consumer, {@see CurriculumOrder}, so this test
 * pins the two backend walks to each other over one fixture course that
 * exercises every branch: module lessons, topics, quizzes at all four
 * parent levels, orphan lessons, and cohort sessions.
 *
 * The comparison drives `NextEntityResolver` to exhaustion — asking for
 * the next candidate, marking it done, repeating — which turns a
 * first-candidate oracle into the full sequence it implies.
 *
 * One documented asymmetry is normalised away: a lesson that has topics
 * is emitted by `CurriculumOrder` (and by `flattenCurriculum`) as its own
 * stop, because `/learn/{lesson}` is a real, lockable destination, while
 * `NextEntityResolver` skips straight to the lesson's first incomplete
 * topic when picking a *candidate*. Ordering is identical; only candidacy
 * differs, so those lessons are filtered out before comparing.
 *
 * @author Tymofii Synianskyi
 */
final class CurriculumOrderParityTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array<string, array<int, list<WP_Post>>> */
	private array $children = [];

	private function post( int $id, string $type, int $parent ): WP_Post {
		$post              = Mockery::mock( 'WP_Post' );
		$post->ID          = $id;
		$post->post_type   = $type;
		$post->post_parent = $parent;
		$post->post_name   = $type . '-' . $id;
		$post->post_title  = $type . ' ' . $id;
		$post->menu_order  = 0;

		assert( $post instanceof WP_Post );
		$this->children[ $type ][ $parent ][] = $post;
		return $post;
	}

	/**
	 * Fixture: two modules (one with a topic-bearing lesson, one with a
	 * plain lesson and a module quiz), an orphan lesson, a cohort session
	 * with its own quiz, and a course-level final exam.
	 */
	private function seed(): void {
		$this->children = [];

		// Module 10 — lesson with topics, plus a lesson quiz.
		$this->post( 10, 'vl_module', 1 );
		$this->post( 100, 'vl_lesson', 10 );
		$this->post( 1000, 'vl_topic', 100 );
		$this->post( 1001, 'vl_topic', 100 );
		$this->post( 500, 'vl_quiz', 100 );

		// Module 11 — plain lesson, then a module-level quiz.
		$this->post( 11, 'vl_module', 1 );
		$this->post( 110, 'vl_lesson', 11 );
		$this->post( 511, 'vl_quiz', 11 );

		// Course-direct orphan lesson.
		$this->post( 200, 'vl_lesson', 1 );

		// Cohort session with an attached quiz.
		$this->post( 300, 'vl_session', 1 );
		$this->post( 600, 'vl_quiz', 300 );

		// Course-level final exam.
		$this->post( 900, 'vl_quiz', 1 );
	}

	/**
	 * @return list<string> `{kind}:{id}` in CurriculumOrder's sequence.
	 */
	private function order_sequence(): array {
		$children = &$this->children;

		$order = new class( $children ) extends CurriculumOrder {

			/** @param array<string, array<int, list<WP_Post>>> $children */
			public function __construct( private array &$children ) {
			}

			protected function query_children( int $parent_id, string $post_type ): array {
				return $this->children[ $post_type ][ $parent_id ] ?? [];
			}

			protected function query_children_of_many( array $parent_ids, string $post_type ): array {
				$out = [];
				foreach ( $parent_ids as $parent_id ) {
					foreach ( $this->children[ $post_type ][ $parent_id ] ?? [] as $post ) {
						$out[] = $post;
					}
				}
				return $out;
			}

			protected function query_sessions( int $course_id ): array {
				return $this->children['vl_session'][ $course_id ] ?? [];
			}

			protected function meta_flag( int $post_id, string $key ): bool {
				return false;
			}

			protected function is_cohort_course( int $course_id ): bool {
				return true;
			}
		};

		return array_map(
			static fn ( CurriculumStop $s ): string => $s->key(),
			$order->for_course( 1 )
		);
	}

	/**
	 * The same fixture shaped the way the node transformers would emit it.
	 *
	 * @return array{modules: list<array<string, mixed>>, orphans: list<array<string, mixed>>, sessions: list<array<string, mixed>>, course_quizzes: list<array<string, mixed>>}
	 */
	private function tree(): array {
		return [
			'modules'        => [
				[
					'lessons' => [ $this->lesson_node( 100, [ 1000, 1001 ], [ 500 ] ) ],
					'quizzes' => [],
				],
				[
					'lessons' => [ $this->lesson_node( 110, [], [] ) ],
					'quizzes' => [ $this->quiz_node( 511 ) ],
				],
			],
			'orphans'        => [ $this->lesson_node( 200, [], [] ) ],
			'sessions'       => [
				[
					'id'              => 300,
					'slug'            => 'vl_session-300',
					'title'           => 'session 300',
					'scheduled_start' => null,
					'is_completed'    => false,
					'quizzes'         => [ $this->quiz_node( 600 ) ],
				],
			],
			'course_quizzes' => [ $this->quiz_node( 900 ) ],
		];
	}

	/**
	 * @param list<int> $topic_ids
	 * @param list<int> $quiz_ids
	 *
	 * @return array<string, mixed>
	 */
	private function lesson_node( int $id, array $topic_ids, array $quiz_ids ): array {
		return [
			'id'       => $id,
			'slug'     => 'vl_lesson-' . $id,
			'progress' => [ 'status' => 'not_started' ],
			'topics'   => array_map(
				static fn ( int $tid ): array => [
					'id'       => $tid,
					'slug'     => 'vl_topic-' . $tid,
					'progress' => [ 'status' => 'not_started' ],
				],
				$topic_ids
			),
			'quizzes'  => array_map( fn ( int $qid ): array => $this->quiz_node( $qid ), $quiz_ids ),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function quiz_node( int $id ): array {
		return [
			'id'     => $id,
			'slug'   => 'vl_quiz-' . $id,
			'status' => 'not_started',
		];
	}

	/**
	 * Drive the resolver to exhaustion: take its next candidate, mark that
	 * entity done, ask again — until it reports the course complete.
	 *
	 * @return list<string> `{kind}:{id}` in NextEntityResolver's sequence.
	 */
	private function resolver_sequence(): array {
		$tree     = $this->tree();
		$resolver = new NextEntityResolver();
		$seen     = [];

		// Bounded so a resolver bug fails the assertion rather than hanging.
		for ( $i = 0; $i < 50; $i++ ) {
			$hint = $resolver->resolve(
				$tree['modules'],
				$tree['orphans'],
				$tree['sessions'],
				$tree['course_quizzes']
			);
			if ( null === $hint ) {
				break;
			}

			$key = $hint['type'] . ':' . $hint['id'];
			self::assertNotContains( $key, $seen, 'NextEntityResolver returned the same stop twice.' );
			$seen[] = $key;

			$tree = $this->mark_done( $tree, (string) $hint['type'], (int) $hint['id'] );
		}

		return $seen;
	}

	/**
	 * @param array<string, mixed> $tree
	 *
	 * @return array<string, mixed>
	 */
	private function mark_done( array $tree, string $type, int $id ): array {
		$walk_quizzes = static function ( array $quizzes ) use ( $type, $id ): array {
			foreach ( $quizzes as $index => $quiz ) {
				if ( 'quiz' === $type && (int) $quiz['id'] === $id ) {
					$quizzes[ $index ]['status'] = 'passed';
				}
			}
			return $quizzes;
		};

		$walk_lesson = static function ( array $lesson ) use ( $type, $id, $walk_quizzes ): array {
			if ( 'lesson' === $type && (int) $lesson['id'] === $id ) {
				$lesson['progress']['status'] = 'completed';
			}
			foreach ( $lesson['topics'] as $index => $topic ) {
				if ( 'topic' === $type && (int) $topic['id'] === $id ) {
					$lesson['topics'][ $index ]['progress']['status'] = 'completed';
				}
			}
			$lesson['quizzes'] = $walk_quizzes( $lesson['quizzes'] );
			return $lesson;
		};

		foreach ( $tree['modules'] as $m_index => $module ) {
			foreach ( $module['lessons'] as $l_index => $lesson ) {
				$tree['modules'][ $m_index ]['lessons'][ $l_index ] = $walk_lesson( $lesson );
			}
			$tree['modules'][ $m_index ]['quizzes'] = $walk_quizzes( $module['quizzes'] );
		}

		foreach ( $tree['orphans'] as $index => $lesson ) {
			$tree['orphans'][ $index ] = $walk_lesson( $lesson );
		}

		foreach ( $tree['sessions'] as $index => $session ) {
			if ( 'session' === $type && (int) $session['id'] === $id ) {
				$tree['sessions'][ $index ]['is_completed'] = true;
			}
			$tree['sessions'][ $index ]['quizzes'] = $walk_quizzes( $tree['sessions'][ $index ]['quizzes'] );
		}

		$tree['course_quizzes'] = $walk_quizzes( $tree['course_quizzes'] );

		return $tree;
	}

	public function test_curriculum_order_matches_the_next_entity_resolver_walk(): void {
		$this->seed();

		// Lesson 100 has topics, so the resolver never nominates it as a
		// candidate — see the class docblock. Every other stop must line up.
		$expected = array_values(
			array_filter(
				$this->order_sequence(),
				static fn ( string $key ): bool => 'lesson:100' !== $key
			)
		);

		self::assertSame( $expected, $this->resolver_sequence() );
	}

	public function test_the_fixture_actually_exercises_every_branch(): void {
		$this->seed();
		$sequence = $this->order_sequence();

		// A parity test that silently stopped covering sessions or orphan
		// lessons would still pass, so assert the fixture's own shape.
		self::assertContains( 'lesson:100', $sequence, 'module lesson with topics' );
		self::assertContains( 'topic:1000', $sequence, 'topic' );
		self::assertContains( 'quiz:500', $sequence, 'lesson-level quiz' );
		self::assertContains( 'quiz:511', $sequence, 'module-level quiz' );
		self::assertContains( 'lesson:200', $sequence, 'orphan lesson' );
		self::assertContains( 'session:300', $sequence, 'cohort session' );
		self::assertContains( 'quiz:600', $sequence, 'session-level quiz' );
		self::assertContains( 'quiz:900', $sequence, 'course-level quiz' );
	}
}
