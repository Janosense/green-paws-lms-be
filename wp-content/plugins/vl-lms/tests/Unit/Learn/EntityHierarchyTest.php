<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Learn;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Learn\EntityHierarchy;
use WP_Post;

final class EntityHierarchyTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array<int, WP_Post> */
	private array $post_index = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->post_index = [];

		$index = &$this->post_index;
		Functions\when( 'get_post' )->alias(
			static function ( int $id ) use ( &$index ): ?WP_Post {
				return $index[ $id ] ?? null;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function makePost( int $id, string $type, int $parent = 0, string $status = 'publish', int $menu_order = 0 ): WP_Post {
		$post              = Mockery::mock( 'WP_Post' );
		$post->ID          = $id;
		$post->post_type   = $type;
		$post->post_parent = $parent;
		$post->post_status = $status;
		$post->menu_order  = $menu_order;

		assert( $post instanceof WP_Post );
		$this->post_index[ $id ] = $post;
		return $post;
	}

	/**
	 * Build an EntityHierarchy that returns the supplied list when asked
	 * for siblings of the given key — bypassing the `WP_Query`-backed
	 * default so tests don't need a WP runtime.
	 *
	 * @param array<string, list<WP_Post>> $sibling_index Keyed by `"{post_type}|{parent_id}"`.
	 */
	private function makeHierarchyWithSiblings( array $sibling_index ): EntityHierarchy {
		return new class( $sibling_index ) extends EntityHierarchy {

			/**
			 * @param array<string, list<WP_Post>> $sibling_index
			 */
			public function __construct( private array $sibling_index ) {
			}

			protected function query_published_siblings( string $post_type, int $parent_id ): array {
				$key = $post_type . '|' . $parent_id;
				return $this->sibling_index[ $key ] ?? [];
			}
		};
	}

	public function test_resolve_course_for_course_post_returns_self(): void {
		$course    = $this->makePost( 1, 'vl_course' );
		$hierarchy = new EntityHierarchy();

		self::assertSame( $course, $hierarchy->resolveCourse( $course ) );
	}

	public function test_resolve_course_for_module_returns_parent_course(): void {
		$course = $this->makePost( 1, 'vl_course' );
		$module = $this->makePost( 2, 'vl_module', 1 );

		$hierarchy = new EntityHierarchy();

		self::assertSame( $course, $hierarchy->resolveCourse( $module ) );
	}

	public function test_resolve_course_for_lesson_with_module_walks_two_levels(): void {
		$course = $this->makePost( 1, 'vl_course' );
		$this->makePost( 2, 'vl_module', 1 );
		$lesson = $this->makePost( 3, 'vl_lesson', 2 );

		$hierarchy = new EntityHierarchy();

		self::assertSame( $course, $hierarchy->resolveCourse( $lesson ) );
	}

	public function test_resolve_course_for_lesson_directly_under_course_walks_one_level(): void {
		$course = $this->makePost( 1, 'vl_course' );
		$lesson = $this->makePost( 3, 'vl_lesson', 1 );

		$hierarchy = new EntityHierarchy();

		self::assertSame( $course, $hierarchy->resolveCourse( $lesson ) );
	}

	public function test_resolve_course_for_topic_walks_three_levels(): void {
		$course = $this->makePost( 1, 'vl_course' );
		$this->makePost( 2, 'vl_module', 1 );
		$this->makePost( 3, 'vl_lesson', 2 );
		$topic = $this->makePost( 4, 'vl_topic', 3 );

		$hierarchy = new EntityHierarchy();

		self::assertSame( $course, $hierarchy->resolveCourse( $topic ) );
	}

	public function test_resolve_course_for_topic_under_module_less_lesson(): void {
		$course = $this->makePost( 1, 'vl_course' );
		$this->makePost( 3, 'vl_lesson', 1 );
		$topic = $this->makePost( 4, 'vl_topic', 3 );

		$hierarchy = new EntityHierarchy();

		self::assertSame( $course, $hierarchy->resolveCourse( $topic ) );
	}

	public function test_resolve_course_returns_null_when_ancestor_unpublished(): void {
		$this->makePost( 1, 'vl_course', 0, 'draft' );
		$lesson = $this->makePost( 3, 'vl_lesson', 1 );

		$hierarchy = new EntityHierarchy();

		self::assertNull( $hierarchy->resolveCourse( $lesson ) );
	}

	public function test_resolve_course_returns_null_when_ancestor_missing(): void {
		$lesson = $this->makePost( 3, 'vl_lesson', 999 );

		$hierarchy = new EntityHierarchy();

		self::assertNull( $hierarchy->resolveCourse( $lesson ) );
	}

	public function test_resolve_course_for_quiz_under_lesson_walks_full_chain(): void {
		$course = $this->makePost( 1, 'vl_course' );
		$this->makePost( 2, 'vl_module', 1 );
		$this->makePost( 3, 'vl_lesson', 2 );
		$quiz = $this->makePost( 5, 'vl_quiz', 3 );

		$hierarchy = new EntityHierarchy();

		self::assertSame( $course, $hierarchy->resolveCourse( $quiz ) );
	}

	public function test_resolve_course_for_quiz_directly_under_course(): void {
		$course = $this->makePost( 1, 'vl_course' );
		$quiz   = $this->makePost( 5, 'vl_quiz', 1 );

		$hierarchy = new EntityHierarchy();

		self::assertSame( $course, $hierarchy->resolveCourse( $quiz ) );
	}

	public function test_resolve_course_for_quiz_under_module(): void {
		$course = $this->makePost( 1, 'vl_course' );
		$this->makePost( 2, 'vl_module', 1 );
		$quiz = $this->makePost( 5, 'vl_quiz', 2 );

		$hierarchy = new EntityHierarchy();

		self::assertSame( $course, $hierarchy->resolveCourse( $quiz ) );
	}

	public function test_resolve_course_for_quiz_under_session(): void {
		$course = $this->makePost( 1, 'vl_course' );
		$this->makePost( 6, 'vl_session', 1 );
		$quiz = $this->makePost( 5, 'vl_quiz', 6 );

		$hierarchy = new EntityHierarchy();

		self::assertSame( $course, $hierarchy->resolveCourse( $quiz ) );
	}

	public function test_resolve_course_for_assignment_under_lesson(): void {
		$course = $this->makePost( 1, 'vl_course' );
		$this->makePost( 2, 'vl_module', 1 );
		$this->makePost( 3, 'vl_lesson', 2 );
		$assignment = $this->makePost( 7, 'vl_assignment', 3 );

		$hierarchy = new EntityHierarchy();

		self::assertSame( $course, $hierarchy->resolveCourse( $assignment ) );
	}

	public function test_resolve_course_for_session_returns_parent_course(): void {
		$course  = $this->makePost( 1, 'vl_course' );
		$session = $this->makePost( 6, 'vl_session', 1 );

		$hierarchy = new EntityHierarchy();

		self::assertSame( $course, $hierarchy->resolveCourse( $session ) );
	}

	public function test_resolve_course_for_quiz_with_unpublished_parent_returns_null(): void {
		$this->makePost( 1, 'vl_course' );
		$this->makePost( 3, 'vl_lesson', 1, 'draft' );
		$quiz = $this->makePost( 5, 'vl_quiz', 3 );

		$hierarchy = new EntityHierarchy();

		self::assertNull( $hierarchy->resolveCourse( $quiz ) );
	}

	public function test_resolve_course_for_quiz_with_no_parent_returns_null(): void {
		$quiz = $this->makePost( 5, 'vl_quiz', 0 );

		$hierarchy = new EntityHierarchy();

		self::assertNull( $hierarchy->resolveCourse( $quiz ) );
	}

	public function test_resolve_module_for_lesson_with_module_returns_module(): void {
		$this->makePost( 1, 'vl_course' );
		$module = $this->makePost( 2, 'vl_module', 1 );
		$lesson = $this->makePost( 3, 'vl_lesson', 2 );

		$hierarchy = new EntityHierarchy();

		self::assertSame( $module, $hierarchy->resolveModule( $lesson ) );
	}

	public function test_resolve_module_for_lesson_directly_under_course_is_null(): void {
		$this->makePost( 1, 'vl_course' );
		$lesson = $this->makePost( 3, 'vl_lesson', 1 );

		$hierarchy = new EntityHierarchy();

		self::assertNull( $hierarchy->resolveModule( $lesson ) );
	}

	public function test_resolve_module_for_topic_returns_module_when_present(): void {
		$this->makePost( 1, 'vl_course' );
		$module = $this->makePost( 2, 'vl_module', 1 );
		$this->makePost( 3, 'vl_lesson', 2 );
		$topic = $this->makePost( 4, 'vl_topic', 3 );

		$hierarchy = new EntityHierarchy();

		self::assertSame( $module, $hierarchy->resolveModule( $topic ) );
	}

	public function test_resolve_module_for_topic_under_module_less_lesson_is_null(): void {
		$this->makePost( 1, 'vl_course' );
		$this->makePost( 3, 'vl_lesson', 1 );
		$topic = $this->makePost( 4, 'vl_topic', 3 );

		$hierarchy = new EntityHierarchy();

		self::assertNull( $hierarchy->resolveModule( $topic ) );
	}

	public function test_resolve_module_for_course_input_is_null(): void {
		$course    = $this->makePost( 1, 'vl_course' );
		$hierarchy = new EntityHierarchy();

		self::assertNull( $hierarchy->resolveModule( $course ) );
	}

	public function test_resolve_module_for_module_input_is_null(): void {
		$this->makePost( 1, 'vl_course' );
		$module    = $this->makePost( 2, 'vl_module', 1 );
		$hierarchy = new EntityHierarchy();

		self::assertNull( $hierarchy->resolveModule( $module ) );
	}

	public function test_resolve_lesson_for_topic_returns_parent_lesson(): void {
		$lesson = $this->makePost( 3, 'vl_lesson' );
		$topic  = $this->makePost( 4, 'vl_topic', 3 );

		$hierarchy = new EntityHierarchy();

		self::assertSame( $lesson, $hierarchy->resolveLesson( $topic ) );
	}

	public function test_resolve_lesson_for_non_topic_input_is_null(): void {
		$lesson = $this->makePost( 3, 'vl_lesson' );

		$hierarchy = new EntityHierarchy();

		self::assertNull( $hierarchy->resolveLesson( $lesson ) );
	}

	public function test_siblings_returns_publish_only_ordered_by_menu_order_then_id(): void {
		$first  = $this->makePost( 10, 'vl_lesson', 1, 'publish', 1 );
		$middle = $this->makePost( 11, 'vl_lesson', 1, 'publish', 2 );
		$last   = $this->makePost( 12, 'vl_lesson', 1, 'publish', 2 );

		$hierarchy = $this->makeHierarchyWithSiblings(
			[
				'vl_lesson|1' => [ $first, $middle, $last ],
			]
		);

		$result = $hierarchy->siblings( $middle );

		self::assertCount( 3, $result );
		self::assertSame( 10, $result[0]->ID );
		self::assertSame( 11, $result[1]->ID );
		self::assertSame( 12, $result[2]->ID );
	}

	public function test_previous_sibling_returns_post_immediately_before_input(): void {
		$first = $this->makePost( 10, 'vl_lesson', 1, 'publish', 1 );
		$mid   = $this->makePost( 11, 'vl_lesson', 1, 'publish', 2 );

		$hierarchy = $this->makeHierarchyWithSiblings(
			[
				'vl_lesson|1' => [ $first, $mid ],
			]
		);

		self::assertSame( $first, $hierarchy->previousSibling( $mid ) );
	}

	public function test_previous_sibling_returns_null_when_input_is_first(): void {
		$first = $this->makePost( 10, 'vl_lesson', 1, 'publish', 1 );
		$last  = $this->makePost( 11, 'vl_lesson', 1, 'publish', 2 );

		$hierarchy = $this->makeHierarchyWithSiblings(
			[
				'vl_lesson|1' => [ $first, $last ],
			]
		);

		self::assertNull( $hierarchy->previousSibling( $first ) );
	}
}
