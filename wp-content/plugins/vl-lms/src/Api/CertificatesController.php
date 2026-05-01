<?php

declare(strict_types=1);

namespace VL\LMS\Api;

use VL\LMS\Auth\RestAuthenticator;
use VL\LMS\Certificate\CertificateService;
use VL\LMS\Certificate\Pdf\PdfGenerator;
use VL\LMS\Domain\Certificate\Certificate;
use VL\LMS\Repositories\CertificateRepository;
use VL\LMS\Support\Logger;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_User;

/**
 * Authed REST controller for the certificate detail / list / download
 * endpoints.
 *
 * Three routes:
 *
 * - `GET /vl/v1/certificates/me` — list the caller's certificates.
 * - `GET /vl/v1/certificates/{uuid}` — full detail (with download URL
 *   and verification URL).
 * - `GET /vl/v1/certificates/{uuid}/download` — stream the PDF (lazy
 *   generation on first call; cached afterwards).
 *
 * All three are gated by the `vl_view_certificate` capability and a
 * fresh user resolution via {@see RestAuthenticator}. Ownership is
 * enforced inside the handlers — a caller's UUID-knowledge is not
 * sufficient to download someone else's certificate.
 *
 * The download path returns 410 (`certificate_revoked`) for soft-
 * revoked certificates, then exits without WP REST envelope wrapping
 * for the binary stream.
 *
 * @author Tymofii Synianskyi
 */
class CertificatesController {

	public const string LIST_ROUTE     = '/certificates/me';
	public const string FETCH_ROUTE    = '/certificates/(?P<uuid>[a-f0-9\-]{36})';
	public const string DOWNLOAD_ROUTE = '/certificates/(?P<uuid>[a-f0-9\-]{36})/download';

	public const string VIEW_CAPABILITY = 'vl_view_certificate';

	public function __construct(
		private readonly string $rest_namespace,
		private readonly CertificateService $service,
		private readonly PdfGenerator $pdf,
		private readonly CertificateRepository $repository,
		private readonly RestAuthenticator $authenticator,
		private readonly Logger $logger
	) {
	}

	public function register_routes(): void {
		$slug_arg = [
			'uuid' => [
				'required' => true,
				'type'     => 'string',
			],
		];

		register_rest_route(
			$this->rest_namespace,
			self::LIST_ROUTE,
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'list_mine' ],
				'permission_callback' => [ $this, 'permission_callback' ],
			]
		);
		register_rest_route(
			$this->rest_namespace,
			self::FETCH_ROUTE,
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'fetch_one' ],
				'permission_callback' => [ $this, 'permission_callback' ],
				'args'                => $slug_arg,
			]
		);
		register_rest_route(
			$this->rest_namespace,
			self::DOWNLOAD_ROUTE,
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'download' ],
				'permission_callback' => [ $this, 'permission_callback' ],
				'args'                => $slug_arg,
			]
		);
	}

	public function permission_callback( WP_REST_Request $request ): bool {
		$user = $this->authenticator->user_from_request( $request );
		if ( ! $user instanceof WP_User ) {
			return false;
		}
		return user_can( $user, self::VIEW_CAPABILITY );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_mine( WP_REST_Request $request ) {
		$user = $this->authenticator->user_from_request( $request );
		if ( ! $user instanceof WP_User ) {
			return $this->error( 'unauthenticated', 401 );
		}

		$rows  = $this->service->find_for_user( (int) $user->ID );
		$items = array_map( fn ( Certificate $c ): array => $this->shape_list_item( $c ), $rows );

		return $this->success( [ 'items' => $items ], 200 );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function fetch_one( WP_REST_Request $request ) {
		$user = $this->authenticator->user_from_request( $request );
		if ( ! $user instanceof WP_User ) {
			return $this->error( 'unauthenticated', 401 );
		}

		$cert = $this->service->find_by_uuid( (string) $request->get_param( 'uuid' ) );
		if ( null === $cert ) {
			return $this->error( 'certificate_not_found', 404 );
		}
		if ( $cert->user_id !== (int) $user->ID ) {
			return $this->error( 'forbidden', 403 );
		}

		return $this->success( $this->shape_detail( $cert ), 200 );
	}

	/**
	 * @return WP_REST_Response|WP_Error|null
	 */
	public function download( WP_REST_Request $request ) {
		$user = $this->authenticator->user_from_request( $request );
		if ( ! $user instanceof WP_User ) {
			return $this->error( 'unauthenticated', 401 );
		}

		$cert = $this->service->find_by_uuid( (string) $request->get_param( 'uuid' ) );
		if ( null === $cert ) {
			return $this->error( 'certificate_not_found', 404 );
		}
		if ( $cert->user_id !== (int) $user->ID ) {
			return $this->error( 'forbidden', 403 );
		}
		if ( null !== $cert->revoked_at ) {
			return new WP_Error(
				'certificate_revoked',
				__( 'Сертифікат відкликано.', 'vl-lms' ),
				[
					'status'     => 410,
					'revoked_at' => $cert->revoked_at->format( \DateTimeInterface::ATOM ),
				]
			);
		}

		try {
			$generated = $this->pdf->generate( $cert );
		} catch ( \Throwable $e ) {
			$this->logger->error(
				'CertificatesController: PDF generation failed',
				[
					'uuid'  => $cert->uuid,
					'error' => $e->getMessage(),
				]
			);
			return $this->error( 'internal_error', 500 );
		}

		if ( ! $generated->cache_hit ) {
			$this->repository->update_pdf_path( $cert->id, $generated->relative_path );
		}

		$this->stream_pdf( $generated->absolute_path, $cert->uuid );
		return null;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function shape_list_item( Certificate $cert ): array {
		$snapshot = $cert->snapshot_data;
		return [
			'uuid'            => $cert->uuid,
			'course_id'       => $cert->course_id,
			'course_title'    => isset( $snapshot['course_title'] ) ? (string) $snapshot['course_title'] : '',
			'issued_at'       => $cert->issued_at->format( \DateTimeInterface::ATOM ),
			'revoked_at'      => null === $cert->revoked_at
				? null
				: $cert->revoked_at->format( \DateTimeInterface::ATOM ),
			'status'          => $cert->status()->value,
			'final_score_pct' => isset( $snapshot['final_score_pct'] ) && null !== $snapshot['final_score_pct']
				? (int) $snapshot['final_score_pct']
				: null,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function shape_detail( Certificate $cert ): array {
		$snapshot = $cert->snapshot_data;

		$base                      = $this->shape_list_item( $cert );
		$base['course_slug']       = isset( $snapshot['course_slug'] ) ? (string) $snapshot['course_slug'] : '';
		$base['learner_full_name'] = isset( $snapshot['learner_full_name'] ) ? (string) $snapshot['learner_full_name'] : '';
		$base['instructor_names']  = $this->normalise_names( $snapshot['instructor_names'] ?? [] );
		$base['issuer_name']       = isset( $snapshot['issuer_name'] ) ? (string) $snapshot['issuer_name'] : '';
		$base['download_url']      = '/wp-json/vl/v1/certificates/' . $cert->uuid . '/download';
		$base['verification_url']  = $this->verification_url( $cert->uuid );
		return $base;
	}

	/**
	 * @param mixed $raw
	 * @return list<string>
	 */
	private function normalise_names( mixed $raw ): array {
		if ( ! is_array( $raw ) ) {
			return [];
		}
		$out = [];
		foreach ( $raw as $name ) {
			if ( is_string( $name ) && '' !== $name ) {
				$out[] = $name;
			}
		}
		return $out;
	}

	private function verification_url( string $uuid ): string {
		$frontend = get_option( 'vl_lms_frontend_url', '' );
		$base     = is_string( $frontend ) && '' !== $frontend
			? rtrim( $frontend, '/' )
			: rtrim( (string) home_url(), '/' );
		return $base . '/certificates/' . $uuid;
	}

	/**
	 * Stream the file with the right headers and exit. Pulled into a
	 * `protected` seam so unit tests can suppress the `exit` and assert
	 * on the captured byte sequence instead.
	 */
	protected function stream_pdf( string $path, string $uuid ): void {
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="certificate-' . $uuid . '.pdf"' );
		$size = (int) filesize( $path );
		if ( $size > 0 ) {
			header( 'Content-Length: ' . $size );
		}
		readfile( $path );
		exit;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function success( array $data, int $status ): WP_REST_Response {
		$response = rest_ensure_response(
			[
				'success' => true,
				'data'    => $data,
			]
		);
		$response->set_status( $status );
		return $response;
	}

	private function error( string $code, int $status ): WP_Error {
		return new WP_Error( $code, $this->message_for( $code ), [ 'status' => $status ] );
	}

	private function message_for( string $code ): string {
		return match ( $code ) {
			'unauthenticated'       => __( 'Authentication required.', 'vl-lms' ),
			'forbidden'             => __( 'Доступ заборонено.', 'vl-lms' ),
			'certificate_not_found' => __( 'Сертифікат не знайдено.', 'vl-lms' ),
			'certificate_revoked'   => __( 'Сертифікат відкликано.', 'vl-lms' ),
			default                 => __( 'Внутрішня помилка.', 'vl-lms' ),
		};
	}
}
