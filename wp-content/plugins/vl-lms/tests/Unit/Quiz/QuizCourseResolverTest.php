<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Quiz;

use PHPUnit\Framework\TestCase;
use VL\LMS\Quiz\QuizCourseResolver;

final class QuizCourseResolverTest extends TestCase {

	public function test_returns_null_for_non_positive_quiz_id(): void {
		$resolver = new QuizCourseResolver();
		self::assertNull( $resolver->find_course_id_for_quiz( 0 ) );
		self::assertNull( $resolver->find_course_id_for_quiz( -3 ) );
	}

	public function test_returns_course_id_when_quiz_is_direct_child(): void {
		$resolver = $this->resolver_with_chain(
			[
				101 => [
					'id'   => 50,
					'type' => 'vl_course',
				],
			]
		);

		self::assertSame( 50, $resolver->find_course_id_for_quiz( 101 ) );
	}

	public function test_walks_lesson_module_course_chain(): void {
		$resolver = $this->resolver_with_chain(
			[
				101 => [
					'id'   => 200,
					'type' => 'vl_lesson',
				],
				200 => [
					'id'   => 110,
					'type' => 'vl_module',
				],
				110 => [
					'id'   => 50,
					'type' => 'vl_course',
				],
			]
		);

		self::assertSame( 50, $resolver->find_course_id_for_quiz( 101 ) );
	}

	public function test_walks_session_course_chain(): void {
		$resolver = $this->resolver_with_chain(
			[
				101 => [
					'id'   => 80,
					'type' => 'vl_session',
				],
				80  => [
					'id'   => 50,
					'type' => 'vl_course',
				],
			]
		);

		self::assertSame( 50, $resolver->find_course_id_for_quiz( 101 ) );
	}

	public function test_returns_null_when_chain_breaks_at_orphan(): void {
		$resolver = $this->resolver_with_chain(
			[
				101 => [
					'id'   => 200,
					'type' => 'vl_lesson',
				],
				// 200 has no entry — chain breaks here.
			]
		);

		self::assertNull( $resolver->find_course_id_for_quiz( 101 ) );
	}

	public function test_returns_null_when_max_hops_exceeded(): void {
		// Long chain with no course at the end.
		$resolver = $this->resolver_with_chain(
			[
				101 => [
					'id'   => 102,
					'type' => 'vl_lesson',
				],
				102 => [
					'id'   => 103,
					'type' => 'vl_lesson',
				],
				103 => [
					'id'   => 104,
					'type' => 'vl_lesson',
				],
				104 => [
					'id'   => 105,
					'type' => 'vl_lesson',
				],
				105 => [
					'id'   => 106,
					'type' => 'vl_lesson',
				],
				106 => [
					'id'   => 50,
					'type' => 'vl_course',
				],
			]
		);

		self::assertNull( $resolver->find_course_id_for_quiz( 101 ) );
	}

	public function test_returns_null_on_cycle(): void {
		$resolver = $this->resolver_with_chain(
			[
				101 => [
					'id'   => 102,
					'type' => 'vl_lesson',
				],
				102 => [
					'id'   => 101,
					'type' => 'vl_lesson',
				],
			]
		);

		self::assertNull( $resolver->find_course_id_for_quiz( 101 ) );
	}

	/**
	 * @param array<int, array{id: int, type: string}|null> $chain
	 */
	private function resolver_with_chain( array $chain ): QuizCourseResolver {
		return new class( $chain ) extends QuizCourseResolver {

			/** @var array<int, array{id: int, type: string}|null> */
			private array $chain;

			/**
			 * @param array<int, array{id: int, type: string}|null> $chain
			 */
			public function __construct( array $chain ) {
				$this->chain = $chain;
			}

			protected function resolve_post_parent( int $post_id ): ?array {
				return $this->chain[ $post_id ] ?? null;
			}
		};
	}
}
