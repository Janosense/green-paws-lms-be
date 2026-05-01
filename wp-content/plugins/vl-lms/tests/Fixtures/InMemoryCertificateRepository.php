<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Fixtures;

use VL\LMS\Domain\Certificate\Certificate;
use VL\LMS\Repositories\CertificateRepository;

/**
 * In-memory double of {@see CertificateRepository} for service-level tests.
 *
 * Same public surface as the real repository, no `$wpdb` calls. Rows live
 * as already-hydrated {@see Certificate} instances keyed by id. The
 * `update_*` paths rebuild the VO immutably so callers always observe a
 * fresh snapshot rather than a mutated reference.
 */
final class InMemoryCertificateRepository extends CertificateRepository {

	/** @var array<int, Certificate> */
	private array $rows = [];

	private int $next_id = 1;

	/** @var callable():\DateTimeImmutable */
	private $clock_fn;

	/**
	 * @param (callable():\DateTimeImmutable)|null $clock UTC clock; defaults to wall-clock UTC.
	 */
	public function __construct( ?callable $clock = null ) {
		parent::__construct( $clock );
		$this->clock_fn = $clock ?? static fn (): \DateTimeImmutable =>
			new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
	}

	public function find( int $id ): ?Certificate {
		return $this->rows[ $id ] ?? null;
	}

	public function find_by_uuid( string $uuid ): ?Certificate {
		foreach ( $this->rows as $row ) {
			if ( $row->uuid === $uuid ) {
				return $row;
			}
		}
		return null;
	}

	public function find_active_for_user_in_course( int $user_id, int $course_id ): ?Certificate {
		$candidates = [];
		foreach ( $this->rows as $row ) {
			if ( $row->user_id === $user_id
				&& $row->course_id === $course_id
				&& null === $row->revoked_at
			) {
				$candidates[] = $row;
			}
		}
		if ( [] === $candidates ) {
			return null;
		}
		usort( $candidates, static fn ( Certificate $a, Certificate $b ): int => $b->issued_at <=> $a->issued_at );
		return $candidates[0];
	}

	/**
	 * @return list<Certificate>
	 */
	public function list_for_enrollment( int $enrollment_id ): array {
		$out = [];
		foreach ( $this->rows as $row ) {
			if ( $row->enrollment_id === $enrollment_id ) {
				$out[] = $row;
			}
		}
		usort( $out, static fn ( Certificate $a, Certificate $b ): int => $b->issued_at <=> $a->issued_at );
		return array_values( $out );
	}

	/**
	 * @return list<Certificate>
	 */
	public function list_for_user( int $user_id ): array {
		$out = [];
		foreach ( $this->rows as $row ) {
			if ( $row->user_id === $user_id ) {
				$out[] = $row;
			}
		}
		usort( $out, static fn ( Certificate $a, Certificate $b ): int => $b->issued_at <=> $a->issued_at );
		return array_values( $out );
	}

	public function insert( Certificate $cert ): int {
		$id  = $this->next_id++;
		$now = ( $this->clock_fn )();

		$this->rows[ $id ] = new Certificate(
			$id,
			$cert->uuid,
			$cert->user_id,
			$cert->course_id,
			$cert->enrollment_id,
			$cert->issued_at,
			$cert->revoked_at,
			$cert->final_score,
			$cert->final_max_score,
			$cert->snapshot_data,
			$cert->pdf_path,
			$now,
			$now
		);
		return $id;
	}

	public function update_revocation( int $id, ?\DateTimeImmutable $revoked_at ): bool {
		if ( ! isset( $this->rows[ $id ] ) ) {
			return false;
		}
		$existing = $this->rows[ $id ];
		$now      = ( $this->clock_fn )();

		$this->rows[ $id ] = new Certificate(
			$existing->id,
			$existing->uuid,
			$existing->user_id,
			$existing->course_id,
			$existing->enrollment_id,
			$existing->issued_at,
			$revoked_at,
			$existing->final_score,
			$existing->final_max_score,
			$existing->snapshot_data,
			$existing->pdf_path,
			$existing->created_at,
			$now
		);
		return true;
	}

	public function update_pdf_path( int $id, string $path ): bool {
		if ( ! isset( $this->rows[ $id ] ) ) {
			return false;
		}
		$existing = $this->rows[ $id ];
		$now      = ( $this->clock_fn )();

		$this->rows[ $id ] = new Certificate(
			$existing->id,
			$existing->uuid,
			$existing->user_id,
			$existing->course_id,
			$existing->enrollment_id,
			$existing->issued_at,
			$existing->revoked_at,
			$existing->final_score,
			$existing->final_max_score,
			$existing->snapshot_data,
			$path,
			$existing->created_at,
			$now
		);
		return true;
	}
}
