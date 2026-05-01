<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Fixtures;

use VL\LMS\Domain\WebinarRegistration\WebinarRegistration;
use VL\LMS\Domain\WebinarRegistration\WebinarRegistrationSource;
use VL\LMS\Domain\WebinarRegistration\WebinarRegistrationStatus;
use VL\LMS\Repositories\WebinarRegistrationRepository;

/**
 * In-memory double of {@see WebinarRegistrationRepository}.
 */
final class InMemoryWebinarRegistrationRepository extends WebinarRegistrationRepository {

	/** @var array<int, array<string, mixed>> */
	private array $rows = [];

	private int $next_id = 1;

	public function register(
		int $webinar_id,
		int $user_id,
		WebinarRegistrationSource $source,
		\DateTimeImmutable $now
	): WebinarRegistration {
		$existing_id = $this->find_id( $webinar_id, $user_id );
		if ( null !== $existing_id ) {
			$this->rows[ $existing_id ]['status']       = WebinarRegistrationStatus::ACTIVE->value;
			$this->rows[ $existing_id ]['source']       = $source->value;
			$this->rows[ $existing_id ]['cancelled_at'] = null;
			$this->rows[ $existing_id ]['updated_at']   = $now->format( 'Y-m-d H:i:s' );
			return WebinarRegistration::from_row( $this->rows[ $existing_id ] );
		}

		$id                = $this->next_id++;
		$now_string        = $now->format( 'Y-m-d H:i:s' );
		$this->rows[ $id ] = [
			'id'                        => $id,
			'webinar_id'                => $webinar_id,
			'user_id'                   => $user_id,
			'status'                    => WebinarRegistrationStatus::ACTIVE->value,
			'source'                    => $source->value,
			'registered_at'             => $now_string,
			'cancelled_at'              => null,
			'attended'                  => 0,
			'attended_duration_seconds' => 0,
			'created_at'                => $now_string,
			'updated_at'                => $now_string,
		];

		return WebinarRegistration::from_row( $this->rows[ $id ] );
	}

	public function cancel( int $webinar_id, int $user_id, \DateTimeImmutable $now ): ?WebinarRegistration {
		$id = $this->find_id( $webinar_id, $user_id );
		if ( null === $id ) {
			return null;
		}
		$this->rows[ $id ]['status']       = WebinarRegistrationStatus::CANCELLED->value;
		$this->rows[ $id ]['cancelled_at'] = $now->format( 'Y-m-d H:i:s' );
		$this->rows[ $id ]['updated_at']   = $now->format( 'Y-m-d H:i:s' );

		return WebinarRegistration::from_row( $this->rows[ $id ] );
	}

	public function find( int $webinar_id, int $user_id ): ?WebinarRegistration {
		$id = $this->find_id( $webinar_id, $user_id );
		if ( null === $id ) {
			return null;
		}
		return WebinarRegistration::from_row( $this->rows[ $id ] );
	}

	public function find_active( int $webinar_id, int $user_id ): ?WebinarRegistration {
		$row = $this->find( $webinar_id, $user_id );
		if ( null === $row || WebinarRegistrationStatus::ACTIVE !== $row->status ) {
			return null;
		}
		return $row;
	}

	/**
	 * @return list<WebinarRegistration>
	 */
	public function list_for_user( int $user_id, ?WebinarRegistrationStatus $status = null ): array {
		$out = [];
		foreach ( $this->rows as $row ) {
			if ( (int) $row['user_id'] !== $user_id ) {
				continue;
			}
			if ( null !== $status && $row['status'] !== $status->value ) {
				continue;
			}
			$out[] = WebinarRegistration::from_row( $row );
		}
		usort( $out, static fn ( WebinarRegistration $a, WebinarRegistration $b ): int => $b->registered_at <=> $a->registered_at );
		return array_values( $out );
	}

	public function count_active_for_webinar( int $webinar_id ): int {
		$count = 0;
		foreach ( $this->rows as $row ) {
			if ( (int) $row['webinar_id'] === $webinar_id
				&& WebinarRegistrationStatus::ACTIVE->value === $row['status']
			) {
				++$count;
			}
		}
		return $count;
	}

	public function mark_attended( int $webinar_id, int $user_id, int $duration_increment_seconds ): void {
		$id = $this->find_id( $webinar_id, $user_id );
		if ( null === $id ) {
			return;
		}
		$delta                         = max( 0, $duration_increment_seconds );
		$this->rows[ $id ]['attended'] = 1;
		$this->rows[ $id ]['attended_duration_seconds'] = ( (int) $this->rows[ $id ]['attended_duration_seconds'] ) + $delta;
	}

	private function find_id( int $webinar_id, int $user_id ): ?int {
		foreach ( $this->rows as $id => $row ) {
			if ( (int) $row['webinar_id'] === $webinar_id && (int) $row['user_id'] === $user_id ) {
				return $id;
			}
		}
		return null;
	}
}
