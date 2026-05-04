<?php

declare(strict_types=1);

namespace VL\LMS\Api;

use VL\LMS\Certificate\CertificateService;
use VL\LMS\Domain\Certificate\Certificate;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Public, unauthenticated REST controller for the certificate
 * verification surface.
 *
 * Single endpoint:
 *
 * - `GET /vl/v1/certificates/{uuid}/public` — minimal payload suitable
 *   for embedding on a public verification page. No `course_id`, no
 *   `user_id`, no `enrollment_id`, no `learner_full_name` — only the
 *   "first name + last initial" display name lives here.
 *
 * The endpoint sets `X-Robots-Tag: noindex,follow` so search engines
 * pass through anchor links but never index the verification page
 * itself.
 *
 * 404 responses use the same error code (`certificate_not_found`)
 * regardless of whether the UUID is malformed, missing, or has a
 * matching row that's been soft-deleted — the goal is to never leak
 * existence by varying error shape.
 *
 * @author Tymofii Synianskyi
 */
class CertificateVerificationController {

	public const string PUBLIC_ROUTE = '/certificates/(?P<uuid>[a-f0-9\-]{36})/public';

	public function __construct(
		private readonly string $rest_namespace,
		private readonly CertificateService $service
	) {
	}

	public function register_routes(): void {
		register_rest_route(
			$this->rest_namespace,
			self::PUBLIC_ROUTE,
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'public_verify' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'uuid' => [
						'required' => true,
						'type'     => 'string',
					],
				],
			]
		);
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function public_verify( WP_REST_Request $request ) {
		$uuid = (string) $request->get_param( 'uuid' );

		$cert = $this->service->find_by_uuid( $uuid );
		if ( null === $cert ) {
			return new WP_Error(
				'certificate_not_found',
				__( 'Сертифікат не знайдено.', 'vl-lms' ),
				[ 'status' => 404 ]
			);
		}

		$response = rest_ensure_response(
			[
				'success' => true,
				'data'    => $this->shape( $cert ),
			]
		);
		$response->set_status( 200 );
		$response->header( 'X-Robots-Tag', 'noindex,follow' );

		return $response;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function shape( Certificate $cert ): array {
		$snapshot = $cert->snapshot_data;

		$instructor_names = [];
		if ( isset( $snapshot['instructor_names'] ) && is_array( $snapshot['instructor_names'] ) ) {
			foreach ( $snapshot['instructor_names'] as $name ) {
				if ( is_string( $name ) && '' !== $name ) {
					$instructor_names[] = $name;
				}
			}
		}

		return [
			'uuid'                 => $cert->uuid,
			'learner_display_name' => isset( $snapshot['learner_display_name'] )
				? (string) $snapshot['learner_display_name']
				: '',
			'course_title'         => isset( $snapshot['course_title'] )
				? (string) $snapshot['course_title']
				: '',
			'issuer_name'          => isset( $snapshot['issuer_name'] )
				? (string) $snapshot['issuer_name']
				: '',
			'instructor_names'     => $instructor_names,
			'issued_at'            => $cert->issued_at->format( \DateTimeInterface::ATOM ),
			'status'               => $cert->status()->value,
			'revoked_at'           => null === $cert->revoked_at
				? null
				: $cert->revoked_at->format( \DateTimeInterface::ATOM ),
			'final_score_pct'      => isset( $snapshot['final_score_pct'] )
				? (int) $snapshot['final_score_pct']
				: null,
		];
	}
}
