<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Learn;

use PHPUnit\Framework\TestCase;
use VL\LMS\Learn\NextEntityResolver;

final class NextEntityResolverTest extends TestCase {

	private NextEntityResolver $resolver;

	protected function setUp(): void {
		parent::setUp();
		$this->resolver = new NextEntityResolver();
	}

	/**
	 * @param list<array<string, mixed>> $topics
	 * @return array<string, mixed>
	 */
	private function lesson( int $id, string $slug, string $progress_status, array $topics = [] ): array {
		return [
			'id'                  => $id,
			'slug'                => $slug,
			'title'               => $slug,
			'menu_order'          => $id,
			'duration_seconds'    => 0,
			'is_preview'          => false,
			'requires_completion' => false,
			'has_topics'          => [] !== $topics,
			'progress'            => $this->progress( $progress_status ),
			'topics'              => $topics,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function topic( int $id, string $slug, string $progress_status ): array {
		return [
			'id'               => $id,
			'slug'             => $slug,
			'title'            => $slug,
			'menu_order'       => $id,
			'duration_seconds' => 0,
			'progress'         => $this->progress( $progress_status ),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function module( int $id, string $slug, array $lessons ): array {
		return [
			'id'         => $id,
			'slug'       => $slug,
			'title'      => $slug,
			'menu_order' => $id,
			'lessons'    => $lessons,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function progress( string $status ): array {
		return [
			'status'           => $status,
			'position_seconds' => null,
			'completed_at'     => null,
		];
	}

	public function test_returns_null_for_empty_curriculum(): void {
		self::assertNull( $this->resolver->resolve( [], [] ) );
	}

	public function test_returns_null_when_all_candidates_completed(): void {
		$module = $this->module(
			1,
			'm1',
			[
				$this->lesson( 11, 'l1', 'completed' ),
				$this->lesson( 12, 'l2', 'completed', [ $this->topic( 21, 't1', 'completed' ) ] ),
			]
		);

		self::assertNull(
			$this->resolver->resolve(
				[ $module ],
				[ $this->lesson( 99, 'orphan', 'completed' ) ]
			)
		);
	}

	public function test_returns_first_lesson_when_first_module_first_lesson_not_completed(): void {
		$module = $this->module(
			1,
			'm1',
			[
				$this->lesson( 11, 'welcome', 'in_progress' ),
				$this->lesson( 12, 'next', 'not_started' ),
			]
		);

		$result = $this->resolver->resolve( [ $module ], [] );

		self::assertSame(
			[
				'type'        => 'lesson',
				'id'          => 11,
				'slug'        => 'welcome',
				'lesson_slug' => 'welcome',
			],
			$result
		);
	}

	public function test_skips_completed_module_to_next_module(): void {
		$first  = $this->module(
			1,
			'm1',
			[ $this->lesson( 11, 'first', 'completed' ) ]
		);
		$second = $this->module(
			2,
			'm2',
			[ $this->lesson( 21, 'second', 'in_progress' ) ]
		);

		$result = $this->resolver->resolve( [ $first, $second ], [] );

		self::assertNotNull( $result );
		self::assertSame( 'lesson', $result['type'] );
		self::assertSame( 'second', $result['slug'] );
	}

	public function test_walks_into_topics_when_lesson_has_them(): void {
		$lesson = $this->lesson(
			11,
			'parent-lesson',
			'not_started',
			[
				$this->topic( 21, 'topic-a', 'completed' ),
				$this->topic( 22, 'topic-b', 'in_progress' ),
			]
		);

		$result = $this->resolver->resolve( [ $this->module( 1, 'm', [ $lesson ] ) ], [] );

		self::assertSame(
			[
				'type'        => 'topic',
				'id'          => 22,
				'slug'        => 'topic-b',
				'lesson_slug' => 'parent-lesson',
			],
			$result
		);
	}

	public function test_topic_walk_continues_when_all_topics_completed_even_if_lesson_not_started(): void {
		// Degenerate edge case: lesson row was never written / fan-up has not
		// run yet, so the lesson itself shows `not_started` while every topic
		// is `completed`. The walker still treats the lesson as exhausted —
		// candidate is the next non-completed leaf elsewhere.
		$lesson = $this->lesson(
			11,
			'parent-lesson',
			'not_started',
			[
				$this->topic( 21, 'a', 'completed' ),
				$this->topic( 22, 'b', 'completed' ),
			]
		);

		$next_module = $this->module(
			2,
			'm2',
			[ $this->lesson( 31, 'fresh', 'not_started' ) ]
		);

		$result = $this->resolver->resolve(
			[ $this->module( 1, 'm1', [ $lesson ] ), $next_module ],
			[]
		);

		self::assertNotNull( $result );
		self::assertSame( 'fresh', $result['slug'] );
	}

	public function test_falls_through_to_orphan_lessons_after_modules_complete(): void {
		$module = $this->module(
			1,
			'm1',
			[ $this->lesson( 11, 'done', 'completed' ) ]
		);

		$result = $this->resolver->resolve(
			[ $module ],
			[
				$this->lesson( 99, 'orphan-a', 'completed' ),
				$this->lesson( 100, 'orphan-b', 'in_progress' ),
			]
		);

		self::assertNotNull( $result );
		self::assertSame( 'orphan-b', $result['slug'] );
		self::assertSame( 'lesson', $result['type'] );
	}

	public function test_lesson_slug_matches_slug_for_lesson_type(): void {
		$result = $this->resolver->resolve(
			[],
			[ $this->lesson( 1, 'sololesson', 'not_started' ) ]
		);

		self::assertNotNull( $result );
		self::assertSame( 'sololesson', $result['slug'] );
		self::assertSame( 'sololesson', $result['lesson_slug'] );
	}
}
