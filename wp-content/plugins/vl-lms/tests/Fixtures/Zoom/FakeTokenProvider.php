<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Fixtures\Zoom;

use VL\LMS\Services\Zoom\TokenProvider;

/**
 * Token provider seam for HttpZoomClient tests. Returns canned tokens in
 * order; `invalidate()` is observable.
 */
final class FakeTokenProvider extends TokenProvider {

	/** @var list<string> */
	private array $tokens;

	public int $invalidate_calls = 0;

	/**
	 * @param list<string> $tokens
	 */
	public function __construct( array $tokens ) {
		// Parent constructor intentionally not called — we override every
		// public method, so we never reach the parent's settings/HTTP/clock.
		$this->tokens = $tokens;
	}

	public function get_token(): string {
		if ( [] === $this->tokens ) {
			throw new \RuntimeException( 'FakeTokenProvider: no more canned tokens.' );
		}
		return array_shift( $this->tokens );
	}

	public function invalidate(): void {
		++$this->invalidate_calls;
	}
}
