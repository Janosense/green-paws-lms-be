<?php

declare(strict_types=1);

namespace VL\LMS\Services\Zoom;

/**
 * The contract Phase 7.1+ consumers (`MeetingSynchronizer`, recording
 * fetchers) depend on. The production implementation is
 * {@see HttpZoomClient}; tests pass an in-memory fake.
 *
 * `$host_user_id_or_me` accepts the literal string `'me'` (S2S OAuth
 * tokens default to the account owner) or a Zoom user id. Phase 7.0
 * always passes `'me'`; the parameter is reserved for Phase 9
 * multi-instructor work.
 *
 * @author Tymofii Synianskyi
 */
interface ZoomClient {

	/**
	 * @param array<string, mixed> $request
	 *
	 * @return array<string, mixed>
	 *
	 * @throws \VL\LMS\Services\Zoom\Exception\ZoomException
	 */
	public function create_meeting( int|string $host_user_id_or_me, array $request ): array;

	/**
	 * @param array<string, mixed> $request
	 *
	 * @throws \VL\LMS\Services\Zoom\Exception\ZoomException
	 */
	public function update_meeting( string $meeting_id, array $request ): void;

	/**
	 * @throws \VL\LMS\Services\Zoom\Exception\ZoomException
	 */
	public function delete_meeting( string $meeting_id, bool $cancel_meeting_reminder = false ): void;

	/**
	 * @return array<string, mixed>
	 *
	 * @throws \VL\LMS\Services\Zoom\Exception\ZoomException
	 */
	public function get_meeting( string $meeting_id ): array;

	/**
	 * @return array<string, mixed>
	 *
	 * @throws \VL\LMS\Services\Zoom\Exception\ZoomException
	 */
	public function list_recordings( string $meeting_id ): array;
}
