<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Fixtures\Zoom;

use VL\LMS\Services\Zoom\ZoomApiHttpClient;

/**
 * Records every HTTP request and replays canned responses in order. The
 * canned responses can be plain envelopes or `\Throwable` (which the
 * fake throws when popped) — that lets us simulate `is_wp_error`
 * network failures.
 */
final class FakeZoomApiHttpClient implements ZoomApiHttpClient {

	/** @var list<array{status: int, body: string}|\Throwable> */
	private array $responses;

	/** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string}> */
	public array $calls = [];

	/**
	 * @param list<array{status: int, body: string}|\Throwable> $responses
	 */
	public function __construct( array $responses ) {
		$this->responses = $responses;
	}

	/**
	 * @param array<string, string> $headers
	 *
	 * @return array{status: int, body: string}
	 */
	public function request( string $method, string $url, array $headers, ?string $body ): array {
		$this->calls[] = [
			'method'  => $method,
			'url'     => $url,
			'headers' => $headers,
			'body'    => $body,
		];

		if ( [] === $this->responses ) {
			throw new \RuntimeException( 'FakeZoomApiHttpClient: no more canned responses.' );
		}

		$next = array_shift( $this->responses );
		if ( $next instanceof \Throwable ) {
			throw $next;
		}
		return $next;
	}
}
