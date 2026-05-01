<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Fixtures\Zoom\Webhook;

use VL\LMS\Services\Zoom\LookupResult;
use VL\LMS\Services\Zoom\PostLookup;

/**
 * Test double for {@see PostLookup}. Ships with a `[meeting_id => LookupResult]`
 * fake table, returns `null` for misses.
 */
final class StubPostLookup extends PostLookup {

	/** @var array<string, LookupResult> */
	public array $by_meeting_id = [];

	public function find_by_meeting_id( string $meeting_id ): ?LookupResult {
		return $this->by_meeting_id[ $meeting_id ] ?? null;
	}
}
