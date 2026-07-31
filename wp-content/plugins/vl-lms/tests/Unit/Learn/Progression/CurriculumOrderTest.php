<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Learn\Progression;

use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Learn\Progression\CurriculumOrder;
use VL\LMS\Learn\Progression\CurriculumStop;
use WP_Post;

/**
 * Order-building coverage. The canonical sequence asserted here is the
 * same one {@see \VL\LMS\Learn\NextEntityResolver} walks and the
 * frontend's `flattenCurriculum` mirrors — see
 * {@see CurriculumOrderParityTest} for the cross-check against the
 * resolver.
 */
final class CurriculumOrderTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array<string, array<int, list<WP_Post>>> Keyed by post type, then parent ID. */
	private array $children = [];

	/** @var array<int, array<string, string>> */
	private array $meta = [];

	private bool $cohort = false;

	private string $completion_mode = 'free';

	private int $query_count = 0;

	protected function setUp(): void {
		parent::setUp();
		$this->children        = [];
		$this->meta            = [];
		$this->cohort          = false;
		$this->completion_mode = 'free';
		$this->query_count     = 0;
	}

	private function post( int $id, string $type, int $parent, string $slug = '', string $title = '' ): WP_Post {
		$post              = Mockery::mock( 'WP_Post' );
		$post->ID          = $id;
		$post->post_type   = $type;
		$post->post_parent = $parent;
		$post->post_name   = '' === $slug ? $type . '-' . $id : $slug;
		$post->post_title  = '' === $title ? ucfirst( $type ) . ' ' . $id : $title;
		$post->menu_order  = 0;

		assert( $post instanceof WP_Post );
		$this->children[ $type ][ $parent ][] = $post;
		return $post;
	}

	private function flag( int $quiz_id, string $key ): void {
		$this->meta[ $quiz_id ][ $key ] = '1';
	}

	private function order(): CurriculumOrder {
		$children    = &$this->children;
		$meta        = &$this->meta;
		$cohort      = &$this->cohort;
		$mode        = &$this->completion_mode;
		$query_count = &$this->query_count;

		return new class( $children, $meta, $cohort, $mode, $query_count ) extends CurriculumOrder {

			/**
			 * @param array<string, array<int, list<WP_Post>>> $children
			 * @param array<int, array<string, string>>        $meta
			 */
			public function __construct(
				private array &$children,
				private array &$meta,
				private bool &$cohort,
				private string &$mode,
				private int &$query_count
			) {
			}

			protected function query_children( int $parent_id, string $post_type ): array {
				++$this->query_count;
				return $this->children[ $post_type ][ $parent_id ] ?? [];
			}

			protected function query_children_of_many( array $parent_ids, string $post_type ): array {
				if ( [] === $parent_ids ) {
					return [];
				}
				++$this->query_count;
				$out = [];
				foreach ( $parent_ids as $parent_id ) {
					foreach ( $this->children[ $post_type ][ $parent_id ] ?? [] as $post ) {
						$out[] = $post;
					}
				}
				return $out;
			}

			protected function query_sessions( int $course_id ): array {
				++$this->query_count;
				return $this->children['vl_session'][ $course_id ] ?? [];
			}

			protected function meta_flag( int $post_id, string $key ): bool {
				return '1' === ( $this->meta[ $post_id ][ $key ] ?? '' );
			}

			protected function is_cohort_course( int $course_id ): bool {
				return $this->cohort;
			}

			protected function completion_mode( int $course_id ): string {
				return $this->mode;
			}
		};
	}

	/**
	 * @return list<string> `{kind}:{id}` for each stop, in order.
	 */
	private function keys( int $course_id = 1 ): array {
		return array_map(
			static fn ( CurriculumStop $s ): string => $s->key(),
			$this->order()->for_course( $course_id )
		);
	}

	public function test_walks_modules_then_lessons_topics_and_quizzes_in_canonical_order(): void {
		$this->post( 10, 'vl_module', 1 );
		$this->post( 100, 'vl_lesson', 10 );
		$this->post( 1000, 'vl_topic', 100 );
		$this->post( 1001, 'vl_topic', 100 );
		$this->post( 500, 'vl_quiz', 100 );   // lesson quiz
		$this->post( 501, 'vl_quiz', 10 );    // module quiz

		self::assertSame(
			[ 'lesson:100', 'topic:1000', 'topic:1001', 'quiz:500', 'quiz:501' ],
			$this->keys()
		);
	}

	public function test_orphan_lessons_follow_every_module(): void {
		$this->post( 10, 'vl_module', 1 );
		$this->post( 100, 'vl_lesson', 10 );
		$this->post( 200, 'vl_lesson', 1 );   // course-direct orphan

		self::assertSame( [ 'lesson:100', 'lesson:200' ], $this->keys() );
	}

	public function test_course_quizzes_come_last(): void {
		$this->post( 10, 'vl_module', 1 );
		$this->post( 100, 'vl_lesson', 10 );
		$this->post( 900, 'vl_quiz', 1 );     // course-level, the final-exam placement

		self::assertSame( [ 'lesson:100', 'quiz:900' ], $this->keys() );
	}

	public function test_cohort_sessions_and_their_quizzes_sit_between_orphans_and_course_quizzes(): void {
		$this->cohort = true;
		$this->post( 200, 'vl_lesson', 1 );
		$this->post( 300, 'vl_session', 1 );
		$this->post( 600, 'vl_quiz', 300 );   // session quiz
		$this->post( 900, 'vl_quiz', 1 );     // course quiz

		self::assertSame(
			[ 'lesson:200', 'session:300', 'quiz:600', 'quiz:900' ],
			$this->keys()
		);
	}

	public function test_self_paced_course_ignores_sessions_entirely(): void {
		$this->post( 300, 'vl_session', 1 );

		self::assertSame( [], $this->keys() );
	}

	/**
	 * `/learn/{lesson}` is a real destination, so it must be lockable even
	 * when the lesson's content is delivered through topics.
	 */
	public function test_a_lesson_with_topics_is_still_emitted_as_its_own_stop(): void {
		$this->post( 100, 'vl_lesson', 1 );
		$this->post( 1000, 'vl_topic', 100 );

		self::assertSame( [ 'lesson:100', 'topic:1000' ], $this->keys() );
	}

	/**
	 * Slug and title feed the sequential lock's `blocking_entity` payload;
	 * `has_topics` is what keeps a lesson-with-topics out of the sequential
	 * frontier.
	 */
	public function test_lesson_stops_record_identity_and_topic_ownership(): void {
		$this->post( 100, 'vl_lesson', 1, 'vstup', 'Вступ' );
		$this->post( 1000, 'vl_topic', 100, 'tema-1', 'Тема 1' );
		$this->post( 200, 'vl_lesson', 1 );

		$stops = $this->order()->for_course( 1 );

		self::assertSame( 'vstup', $stops[0]->slug );
		self::assertSame( 'Вступ', $stops[0]->title );
		self::assertTrue( $stops[0]->has_topics );

		self::assertSame( 'tema-1', $stops[1]->slug );
		self::assertSame( 'Тема 1', $stops[1]->title );

		self::assertFalse( $stops[2]->has_topics );
	}

	public function test_is_sequential_course_requires_self_paced_and_sequential_meta(): void {
		$cases = [
			[ false, 'sequential', true ],
			[ false, 'free', false ],
			[ true, 'sequential', false ],
			[ true, 'free', false ],
		];

		foreach ( $cases as [ $cohort, $mode, $expected ] ) {
			$this->cohort          = $cohort;
			$this->completion_mode = $mode;

			self::assertSame(
				$expected,
				$this->order()->is_sequential_course( 1 ),
				sprintf( 'cohort=%s mode=%s', $cohort ? 'true' : 'false', $mode )
			);
		}
	}

	public function test_quiz_stops_carry_slug_title_and_the_gating_flags(): void {
		$this->post( 500, 'vl_quiz', 1, 'modul-1-test', 'Тест до модуля 1' );
		$this->flag( 500, '_vl_quiz_blocks_progression' );
		$this->flag( 500, '_vl_quiz_is_final_exam' );

		$stops = $this->order()->for_course( 1 );

		self::assertCount( 1, $stops );
		self::assertSame( 'modul-1-test', $stops[0]->slug );
		self::assertSame( 'Тест до модуля 1', $stops[0]->title );
		self::assertTrue( $stops[0]->blocks_progression );
		self::assertFalse( $stops[0]->requires_all_quizzes );
		self::assertTrue( $stops[0]->is_final_exam );
	}

	public function test_has_gated_quizzes_is_false_without_flags(): void {
		$this->post( 500, 'vl_quiz', 1 );
		$order = $this->order();

		self::assertFalse( $order->has_gated_quizzes( $order->for_course( 1 ) ) );
	}

	public function test_has_gated_quizzes_detects_either_flag(): void {
		$this->post( 500, 'vl_quiz', 1 );
		$this->flag( 500, '_vl_quiz_requires_all_quizzes_passed' );
		$order = $this->order();

		self::assertTrue( $order->has_gated_quizzes( $order->for_course( 1 ) ) );
	}

	/**
	 * The gates call this on ordinary lesson reads, so the query count must
	 * not scale with course size.
	 */
	public function test_query_count_is_bounded_regardless_of_course_size(): void {
		$this->cohort = true;
		for ( $m = 1; $m <= 8; $m++ ) {
			$module_id = 10 + $m;
			$this->post( $module_id, 'vl_module', 1 );
			for ( $l = 1; $l <= 8; $l++ ) {
				$lesson_id = $module_id * 100 + $l;
				$this->post( $lesson_id, 'vl_lesson', $module_id );
				$this->post( $lesson_id * 10, 'vl_topic', $lesson_id );
				$this->post( $lesson_id * 10 + 1, 'vl_quiz', $lesson_id );
			}
		}
		$this->post( 300, 'vl_session', 1 );

		$this->order()->for_course( 1 );

		// modules, lessons, topics, sessions, quizzes — five, for 8 modules
		// and 64 lessons.
		self::assertSame( 5, $this->query_count );
	}

	public function test_for_course_is_memoised_per_request(): void {
		$this->post( 100, 'vl_lesson', 1 );
		$order = $this->order();

		$order->for_course( 1 );
		$after_first = $this->query_count;
		$order->for_course( 1 );

		self::assertSame( $after_first, $this->query_count );
	}

	public function test_empty_course_yields_no_stops(): void {
		self::assertSame( [], $this->keys() );
	}

	public function test_counts_for_tallies_modules_lessons_topics_and_quizzes(): void {
		$this->post( 10, 'vl_module', 1 );
		$this->post( 11, 'vl_module', 1 );    // empty module still counts
		$this->post( 100, 'vl_lesson', 10 );
		$this->post( 200, 'vl_lesson', 1 );   // course-direct orphan
		$this->post( 1000, 'vl_topic', 100 );
		$this->post( 1001, 'vl_topic', 100 );
		$this->post( 500, 'vl_quiz', 100 );   // lesson quiz
		$this->post( 501, 'vl_quiz', 10 );    // module quiz
		$this->post( 900, 'vl_quiz', 1 );     // course quiz

		$counts = $this->order()->counts_for( 1 );

		self::assertSame( 2, $counts->modules );
		self::assertSame( 2, $counts->lessons );
		self::assertSame( 2, $counts->topics );
		self::assertSame( 3, $counts->quizzes );
		self::assertFalse( $counts->has_final_exam );
	}

	public function test_counts_for_flags_final_exam_only_when_meta_is_set(): void {
		$this->post( 900, 'vl_quiz', 1 );
		$this->flag( 900, '_vl_quiz_is_final_exam' );

		self::assertTrue( $this->order()->counts_for( 1 )->has_final_exam );
	}

	public function test_counts_for_excludes_session_quizzes_on_self_paced_courses(): void {
		$this->post( 300, 'vl_session', 1 );
		$this->post( 600, 'vl_quiz', 300 );

		self::assertSame( 0, $this->order()->counts_for( 1 )->quizzes );
	}

	public function test_counts_for_includes_session_quizzes_but_not_sessions_on_cohort_courses(): void {
		$this->cohort = true;
		$this->post( 300, 'vl_session', 1 );
		$this->post( 600, 'vl_quiz', 300 );

		$counts = $this->order()->counts_for( 1 );

		self::assertSame( 1, $counts->quizzes );
		self::assertSame( 0, $counts->modules );
		self::assertSame( 0, $counts->lessons );
	}

	public function test_counts_for_adds_no_queries_beyond_for_course(): void {
		$this->post( 10, 'vl_module', 1 );
		$this->post( 100, 'vl_lesson', 10 );
		$order = $this->order();

		$order->counts_for( 1 );
		$cold = $this->query_count;
		$order->counts_for( 1 );

		// modules, lessons, topics, quizzes — four on a self-paced course.
		self::assertSame( 4, $cold );
		self::assertSame( $cold, $this->query_count );
	}

	public function test_counts_for_empty_course_is_all_zero(): void {
		$counts = $this->order()->counts_for( 1 );

		self::assertSame( 0, $counts->modules );
		self::assertSame( 0, $counts->lessons );
		self::assertSame( 0, $counts->topics );
		self::assertSame( 0, $counts->quizzes );
		self::assertFalse( $counts->has_final_exam );
	}
}
