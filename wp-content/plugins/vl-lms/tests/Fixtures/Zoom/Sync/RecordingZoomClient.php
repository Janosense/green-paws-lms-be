<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Fixtures\Zoom\Sync;

use VL\LMS\Services\Zoom\ZoomClient;

/**
 * Records every {@see ZoomClient} call and replays canned responses /
 * exceptions in order, keyed per method. Lets the
 * {@see \VL\LMS\Tests\Unit\Services\Zoom\Sync\MeetingSynchronizerTest}
 * stub each branch without hand-rolling a Mockery expectation per case.
 */
final class RecordingZoomClient implements ZoomClient {

	/** @var list<array{method: string, args: array<string, mixed>}> */
	public array $calls = [];

	/** @var array<string, mixed> */
	public array $create_response = [
		'id'        => 'mtg-new',
		'join_url'  => 'https://zoom.us/j/mtg-new',
		'start_url' => 'https://zoom.us/s/mtg-new',
		'password'  => 'returned-pw',
	];

	public ?\Throwable $create_throws = null;

	public ?\Throwable $update_throws = null;

	public ?\Throwable $delete_throws = null;

	/**
	 * @param array<string, mixed> $request
	 *
	 * @return array<string, mixed>
	 */
	public function create_meeting( int|string $host_user_id_or_me, array $request ): array {
		$this->calls[] = [
			'method' => 'create_meeting',
			'args'   => [
				'host'    => $host_user_id_or_me,
				'request' => $request,
			],
		];
		if ( null !== $this->create_throws ) {
			throw $this->create_throws;
		}
		return $this->create_response;
	}

	/**
	 * @param array<string, mixed> $request
	 */
	public function update_meeting( string $meeting_id, array $request ): void {
		$this->calls[] = [
			'method' => 'update_meeting',
			'args'   => [
				'meeting_id' => $meeting_id,
				'request'    => $request,
			],
		];
		if ( null !== $this->update_throws ) {
			throw $this->update_throws;
		}
	}

	public function delete_meeting( string $meeting_id, bool $cancel_meeting_reminder = false ): void {
		$this->calls[] = [
			'method' => 'delete_meeting',
			'args'   => [
				'meeting_id'              => $meeting_id,
				'cancel_meeting_reminder' => $cancel_meeting_reminder,
			],
		];
		if ( null !== $this->delete_throws ) {
			throw $this->delete_throws;
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_meeting( string $meeting_id ): array {
		$this->calls[] = [
			'method' => 'get_meeting',
			'args'   => [ 'meeting_id' => $meeting_id ],
		];
		return [];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function list_recordings( string $meeting_id ): array {
		$this->calls[] = [
			'method' => 'list_recordings',
			'args'   => [ 'meeting_id' => $meeting_id ],
		];
		return [];
	}
}
