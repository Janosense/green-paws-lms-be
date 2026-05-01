<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Fixtures;

use VL\LMS\Domain\SessionAttendance\SessionAttendance;
use VL\LMS\Repositories\SessionAttendanceRepository;

/**
 * In-memory double of {@see SessionAttendanceRepository} for service-level
 * tests. Same public surface as the real repository, no `$wpdb` calls.
 */
final class InMemorySessionAttendanceRepository extends SessionAttendanceRepository {

	/** @var array<int, array<string, mixed>> */
	private array $rows = [];

	private int $next_id = 1;

	public function record_join(
		int $session_id,
		?int $user_id,
		string $zoom_participant_uuid,
		?string $participant_email,
		?string $participant_name,
		\DateTimeImmutable $joined_at
	): SessionAttendance {
		$existing = $this->find_open( $session_id, $zoom_participant_uuid );
		if ( null !== $existing ) {
			return $existing;
		}

		$id  = $this->next_id++;
		$now = gmdate( 'Y-m-d H:i:s' );

		$this->rows[ $id ] = [
			'id'                    => $id,
			'session_id'            => $session_id,
			'user_id'               => $user_id,
			'zoom_participant_uuid' => $zoom_participant_uuid,
			'participant_email'     => $participant_email,
			'participant_name'      => $participant_name,
			'joined_at'             => $joined_at->format( 'Y-m-d H:i:s' ),
			'left_at'               => null,
			'duration_seconds'      => null,
			'created_at'            => $now,
			'updated_at'            => $now,
		];

		return SessionAttendance::from_row( $this->rows[ $id ] );
	}

	public function record_leave(
		int $session_id,
		string $zoom_participant_uuid,
		\DateTimeImmutable $left_at
	): ?SessionAttendance {
		foreach ( $this->rows as $id => $row ) {
			if ( (int) $row['session_id'] !== $session_id ) {
				continue;
			}
			if ( $row['zoom_participant_uuid'] !== $zoom_participant_uuid ) {
				continue;
			}
			if ( null !== $row['left_at'] ) {
				continue;
			}

			$joined   = new \DateTimeImmutable( (string) $row['joined_at'], new \DateTimeZone( 'UTC' ) );
			$duration = $left_at->getTimestamp() - $joined->getTimestamp();
			if ( $duration < 0 ) {
				$duration = 0;
			}

			$this->rows[ $id ]['left_at']          = $left_at->format( 'Y-m-d H:i:s' );
			$this->rows[ $id ]['duration_seconds'] = $duration;
			$this->rows[ $id ]['updated_at']       = gmdate( 'Y-m-d H:i:s' );

			return SessionAttendance::from_row( $this->rows[ $id ] );
		}

		return null;
	}

	public function find_open( int $session_id, string $zoom_participant_uuid ): ?SessionAttendance {
		foreach ( $this->rows as $row ) {
			if ( (int) $row['session_id'] === $session_id
				&& $row['zoom_participant_uuid'] === $zoom_participant_uuid
				&& null === $row['left_at']
			) {
				return SessionAttendance::from_row( $row );
			}
		}
		return null;
	}

	/**
	 * @return list<SessionAttendance>
	 */
	public function list_for_session( int $session_id ): array {
		$out = [];
		foreach ( $this->rows as $row ) {
			if ( (int) $row['session_id'] === $session_id ) {
				$out[] = SessionAttendance::from_row( $row );
			}
		}
		usort( $out, static fn ( SessionAttendance $a, SessionAttendance $b ): int => $a->joined_at <=> $b->joined_at );
		return array_values( $out );
	}

	/**
	 * @return list<SessionAttendance>
	 */
	public function list_for_user( int $user_id, ?int $session_id = null ): array {
		$out = [];
		foreach ( $this->rows as $row ) {
			if ( null === $row['user_id'] || (int) $row['user_id'] !== $user_id ) {
				continue;
			}
			if ( null !== $session_id && (int) $row['session_id'] !== $session_id ) {
				continue;
			}
			$out[] = SessionAttendance::from_row( $row );
		}
		usort( $out, static fn ( SessionAttendance $a, SessionAttendance $b ): int => $b->joined_at <=> $a->joined_at );
		return array_values( $out );
	}
}
