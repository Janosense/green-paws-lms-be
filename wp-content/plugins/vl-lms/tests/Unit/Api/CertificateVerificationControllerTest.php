<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Api;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Api\CertificateVerificationController;
use VL\LMS\Certificate\CertificateService;
use VL\LMS\Domain\Certificate\Certificate;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class CertificateVerificationControllerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface&CertificateService */
	private $service;

	private CertificateVerificationController $controller;

	private \DateTimeImmutable $now;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'rest_ensure_response' )->alias(
			static function ( $payload ): WP_REST_Response {
				$response          = Mockery::mock( WP_REST_Response::class )->makePartial();
				$response->headers = [];
				$response->shouldReceive( 'set_status' )->andReturnUsing(
					function ( int $status ) use ( $response ): WP_REST_Response {
						$response->status = $status;
						return $response;
					}
				);
				$response->shouldReceive( 'header' )->andReturnUsing(
					function ( string $name, string $value ) use ( $response ): WP_REST_Response {
						$response->headers[ $name ] = $value;
						return $response;
					}
				);
				$response->shouldReceive( 'get_data' )->andReturn( $payload );
				$response->status = 200;
				return $response;
			}
		);

		$this->now        = new \DateTimeImmutable( '2026-04-29T10:00:00+00:00' );
		$this->service    = Mockery::mock( CertificateService::class );
		$this->controller = new CertificateVerificationController( 'vl/v1', $this->service );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function request( string $uuid ): WP_REST_Request {
		$req = Mockery::mock( WP_REST_Request::class );
		$req->shouldReceive( 'get_param' )->with( 'uuid' )->andReturn( $uuid );
		assert( $req instanceof WP_REST_Request );
		return $req;
	}

	private function certificate(
		string $uuid = '8e2c4d2a-0000-4000-8000-000000000001',
		?\DateTimeImmutable $revoked_at = null
	): Certificate {
		return new Certificate(
			1,
			$uuid,
			5,
			50,
			21,
			$this->now,
			$revoked_at,
			92,
			100,
			[
				'course_title'         => 'Course X',
				'learner_display_name' => 'Богдан К.',
				'issuer_name'          => 'Green Paws LMS',
				'instructor_names'     => [ 'Олена Ш.' ],
				'final_score_pct'      => 92,
			],
			null,
			$this->now,
			$this->now
		);
	}

	public function test_returns_minimal_shape_for_active_certificate(): void {
		$cert = $this->certificate();
		$this->service->shouldReceive( 'find_by_uuid' )->with( $cert->uuid )->andReturn( $cert );

		$res = $this->controller->public_verify( $this->request( $cert->uuid ) );

		self::assertInstanceOf( WP_REST_Response::class, $res );
		$body = $res->get_data();

		self::assertSame( $cert->uuid, $body['data']['uuid'] );
		self::assertSame( 'Богдан К.', $body['data']['learner_display_name'] );
		self::assertSame( 'Course X', $body['data']['course_title'] );
		self::assertSame( 'Green Paws LMS', $body['data']['issuer_name'] );
		self::assertSame( [ 'Олена Ш.' ], $body['data']['instructor_names'] );
		self::assertSame( 'active', $body['data']['status'] );
		self::assertNull( $body['data']['revoked_at'] );
		self::assertSame( 92, $body['data']['final_score_pct'] );

		// Privacy: ensure NO course_id / user_id / enrollment_id / learner_full_name.
		self::assertArrayNotHasKey( 'course_id', $body['data'] );
		self::assertArrayNotHasKey( 'user_id', $body['data'] );
		self::assertArrayNotHasKey( 'enrollment_id', $body['data'] );
		self::assertArrayNotHasKey( 'learner_full_name', $body['data'] );
	}

	public function test_revoked_certificate_includes_revoked_at_and_status(): void {
		$cert = $this->certificate( '8e2c4d2a-0000-4000-8000-000000000001', $this->now );
		$this->service->shouldReceive( 'find_by_uuid' )->andReturn( $cert );

		$res  = $this->controller->public_verify( $this->request( $cert->uuid ) );
		$body = $res->get_data();

		self::assertSame( 'revoked', $body['data']['status'] );
		self::assertSame( $this->now->format( \DateTimeInterface::ATOM ), $body['data']['revoked_at'] );
	}

	public function test_x_robots_tag_header_set(): void {
		$cert = $this->certificate();
		$this->service->shouldReceive( 'find_by_uuid' )->andReturn( $cert );

		$res = $this->controller->public_verify( $this->request( $cert->uuid ) );

		self::assertInstanceOf( WP_REST_Response::class, $res );
		self::assertSame( 'noindex,follow', $res->headers['X-Robots-Tag'] );
	}

	public function test_returns_404_for_missing_uuid(): void {
		$this->service->shouldReceive( 'find_by_uuid' )->andReturn( null );

		$res = $this->controller->public_verify( $this->request( 'missing-uuid' ) );

		self::assertInstanceOf( WP_Error::class, $res );
		self::assertSame( 'certificate_not_found', $res->get_error_code() );
		self::assertSame( 404, $res->get_error_data()['status'] );
	}
}
