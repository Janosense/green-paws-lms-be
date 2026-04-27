<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Catalog\Detail\Support;

use VL\LMS\Catalog\Detail\PostFinder;
use WP_Post;

/**
 * In-memory `PostFinder` for unit tests — substitutes for the live
 * `WP_Query`-backed runner.
 *
 * Stubs are matchers `(array $args): bool` paired with the response
 * `list<WP_Post>` to return when the matcher accepts. Stubs are tried
 * in registration order; the first match wins. Unmatched calls return
 * an empty list and are still tracked in {@see self::calls()} so tests
 * can assert against them.
 */
final class FakePostFinder extends PostFinder {

	/** @var list<array{matcher: callable(array<string, mixed>): bool, posts: list<WP_Post>}> */
	private array $stubs = [];

	/** @var list<array{args: array<string, mixed>, posts: list<WP_Post>}> */
	private array $calls = [];

	/**
	 * @param callable(array<string, mixed>): bool $matcher
	 * @param list<WP_Post>                        $posts
	 */
	public function stub( callable $matcher, array $posts ): self {
		$this->stubs[] = [
			'matcher' => $matcher,
			'posts'   => $posts,
		];
		return $this;
	}

	/**
	 * @param array<string, mixed> $args
	 *
	 * @return list<WP_Post>
	 */
	public function find( array $args ): array {
		foreach ( $this->stubs as $stub ) {
			if ( ( $stub['matcher'] )( $args ) ) {
				$this->calls[] = [
					'args'  => $args,
					'posts' => $stub['posts'],
				];
				return $stub['posts'];
			}
		}

		$this->calls[] = [
			'args'  => $args,
			'posts' => [],
		];
		return [];
	}

	/**
	 * @return list<array{args: array<string, mixed>, posts: list<WP_Post>}>
	 */
	public function calls(): array {
		return $this->calls;
	}

	/**
	 * @param callable(array<string, mixed>): bool $predicate
	 */
	public function call_count_matching( callable $predicate ): int {
		$count = 0;
		foreach ( $this->calls as $call ) {
			if ( $predicate( $call['args'] ) ) {
				++$count;
			}
		}
		return $count;
	}
}
