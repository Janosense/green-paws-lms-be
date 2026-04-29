<?php

declare(strict_types=1);

namespace VL\LMS\Domain\Certificate;

/**
 * Immutable data carrier for one row of `{prefix}vl_certificates`.
 *
 * Public identity is the `uuid` column (RFC 4122 v4) — used in
 * verification URLs and printed on the certificate itself. The
 * `snapshot_data` JSON blob freezes everything the PDF renderer needs
 * (course title, learner names, instructor names, issued-at, score) so
 * later edits to the source course never alter a previously-issued
 * certificate. `pdf_path` is the relative path under
 * `wp-content/uploads/certificates/`, populated lazily on first render
 * (Phase 6.3) — null means "not rendered yet".
 *
 * @author Tymofii Synianskyi
 */
final class Certificate {

	/**
	 * @param array<string, mixed> $snapshot_data Decoded snapshot payload for PDF rendering.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $uuid,
		public readonly int $user_id,
		public readonly int $course_id,
		public readonly int $enrollment_id,
		public readonly \DateTimeImmutable $issued_at,
		public readonly ?\DateTimeImmutable $revoked_at,
		public readonly ?int $final_score,
		public readonly ?int $final_max_score,
		public readonly array $snapshot_data,
		public readonly ?string $pdf_path,
		public readonly \DateTimeImmutable $created_at,
		public readonly \DateTimeImmutable $updated_at
	) {
	}

	/**
	 * @param array<string, mixed> $row
	 */
	public static function from_array( array $row ): self {
		return new self(
			(int) $row['id'],
			(string) $row['uuid'],
			(int) $row['user_id'],
			(int) $row['course_id'],
			(int) $row['enrollment_id'],
			self::datetime( (string) $row['issued_at'] ),
			self::nullable_datetime( $row['revoked_at'] ?? null ),
			self::nullable_int( $row['final_score'] ?? null ),
			self::nullable_int( $row['final_max_score'] ?? null ),
			self::decode_snapshot( $row['snapshot_data'] ?? null ),
			self::nullable_string( $row['pdf_path'] ?? null ),
			self::datetime( (string) $row['created_at'] ),
			self::datetime( (string) $row['updated_at'] )
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		$encoded = json_encode( $this->snapshot_data );
		return [
			'id'              => $this->id,
			'uuid'            => $this->uuid,
			'user_id'         => $this->user_id,
			'course_id'       => $this->course_id,
			'enrollment_id'   => $this->enrollment_id,
			'issued_at'       => $this->issued_at->format( 'Y-m-d H:i:s' ),
			'revoked_at'      => null === $this->revoked_at ? null : $this->revoked_at->format( 'Y-m-d H:i:s' ),
			'final_score'     => $this->final_score,
			'final_max_score' => $this->final_max_score,
			'snapshot_data'   => false === $encoded ? '{}' : $encoded,
			'pdf_path'        => $this->pdf_path,
			'created_at'      => $this->created_at->format( 'Y-m-d H:i:s' ),
			'updated_at'      => $this->updated_at->format( 'Y-m-d H:i:s' ),
		];
	}

	public function status(): CertificateStatus {
		return null === $this->revoked_at
			? CertificateStatus::ACTIVE
			: CertificateStatus::REVOKED;
	}

	private static function datetime( string $value ): \DateTimeImmutable {
		return new \DateTimeImmutable( $value, new \DateTimeZone( 'UTC' ) );
	}

	private static function nullable_datetime( mixed $value ): ?\DateTimeImmutable {
		if ( null === $value || '' === $value ) {
			return null;
		}
		return self::datetime( (string) $value );
	}

	private static function nullable_int( mixed $value ): ?int {
		if ( null === $value || '' === $value ) {
			return null;
		}
		return (int) $value;
	}

	private static function nullable_string( mixed $value ): ?string {
		if ( null === $value || '' === $value ) {
			return null;
		}
		return (string) $value;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function decode_snapshot( mixed $value ): array {
		if ( null === $value || '' === $value ) {
			return [];
		}
		$decoded = json_decode( (string) $value, true );
		if ( ! is_array( $decoded ) ) {
			return [];
		}
		/** @var array<string, mixed> $decoded */
		return $decoded;
	}
}
