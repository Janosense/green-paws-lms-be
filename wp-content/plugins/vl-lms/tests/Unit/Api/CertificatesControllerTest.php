<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Api;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Api\CertificatesController;
use VL\LMS\Auth\RestAuthenticator;
use VL\LMS\Certificate\CertificateService;
use VL\LMS\Certificate\Pdf\GeneratedPdf;
use VL\LMS\Certificate\Pdf\PdfGenerator;
use VL\LMS\Domain\Certificate\Certificate;
use VL\LMS\Repositories\CertificateRepository;
use VL\LMS\Support\Logger;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_User;

final class CertificatesControllerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface&CertificateService */
	private $service;

	/** @var Mockery\MockInterface&PdfGenerator */
	private $pdf;

	/** @var Mockery\MockInterface&CertificateRepository */
	private $repo;

	/** @var Mockery\MockInterface&RestAuthenticator */
	private $authenticator;

	/** @var Mockery\MockInterface&Logger */
	private $logger;

	private CertificatesController $controller;

	private \DateTimeImmutable $now;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'home_url' )->justReturn( 'https://example.test' );
		Functions\when( 'get_option' )->justReturn( '' );
		Functions\when( 'rest_ensure_response' )->alias(
			static function ( $payload ): WP_REST_Response {
				$response = Mockery::mock( WP_REST_Response::class )->makePartial();
				$response->shouldReceive( 'set_status' )->andReturnUsing(
					function ( int $status ) use ( $response ): WP_REST_Response {
						$response->status = $status;
						return $response;
					}
				);
				$response->shouldReceive( 'get_data' )->andReturn( $payload );
				$response->status = 200;
				return $response;
			}
		);
		Functions\when( 'user_can' )->justReturn( true );

		$this->now           = new \DateTimeImmutable( '2026-04-29T10:00:00+00:00' );
		$this->service       = Mockery::mock( CertificateService::class );
		$this->pdf           = Mockery::mock( PdfGenerator::class );
		$this->repo          = Mockery::mock( CertificateRepository::class );
		$this->authenticator = Mockery::mock( RestAuthenticator::class );
		$this->logger        = Mockery::mock( Logger::class )->shouldIgnoreMissing();

		$this->controller = new class(
			'vl/v1',
			$this->service,
			$this->pdf,
			$this->repo,
			$this->authenticator,
			$this->logger
		) extends CertificatesController {
			public ?string $streamed_path = null;
			public ?string $streamed_uuid = null;

			protected function stream_pdf( string $path, string $uuid ): void {
				$this->streamed_path = $path;
				$this->streamed_uuid = $uuid;
				// Skip exit so the test can assert and continue.
			}
		};
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function user( int $id ): WP_User {
		$user     = Mockery::mock( 'WP_User' );
		$user->ID = $id;
		assert( $user instanceof WP_User );
		return $user;
	}

	private function request( array $params = [] ): WP_REST_Request {
		$req = Mockery::mock( WP_REST_Request::class );
		$req->shouldReceive( 'get_param' )->andReturnUsing(
			static fn ( string $name ): mixed => $params[ $name ] ?? null
		);
		assert( $req instanceof WP_REST_Request );
		return $req;
	}

	private function certificate(
		string $uuid = '8e2c4d2a-0000-4000-8000-000000000001',
		int $user_id = 5,
		?\DateTimeImmutable $revoked_at = null
	): Certificate {
		return new Certificate(
			1,
			$uuid,
			$user_id,
			50,
			21,
			$this->now,
			$revoked_at,
			92,
			100,
			[
				'course_title'      => 'Course X',
				'course_slug'       => 'course-x',
				'learner_full_name' => 'Богдан Коваль',
				'instructor_names'  => [ 'Олена Ш.' ],
				'issuer_name'       => 'Green Paws LMS',
				'final_score_pct'   => 92,
			],
			null,
			$this->now,
			$this->now
		);
	}

	public function test_list_mine_returns_items_for_authed_user(): void {
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $this->user( 5 ) );
		$this->service->shouldReceive( 'find_for_user' )->with( 5 )->andReturn( [ $this->certificate() ] );

		$res = $this->controller->list_mine( $this->request() );

		self::assertInstanceOf( WP_REST_Response::class, $res );
		$body = $res->get_data();
		self::assertSame( 200, $res->status );
		self::assertCount( 1, $body['data']['items'] );
		self::assertSame( 'Course X', $body['data']['items'][0]['course_title'] );
		self::assertSame( 'active', $body['data']['items'][0]['status'] );
	}

	public function test_list_mine_returns_empty_array_when_no_certificates(): void {
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $this->user( 5 ) );
		$this->service->shouldReceive( 'find_for_user' )->andReturn( [] );

		$res  = $this->controller->list_mine( $this->request() );
		$body = $res->get_data();
		self::assertSame( [], $body['data']['items'] );
	}

	public function test_fetch_one_returns_detail_for_owner(): void {
		$cert = $this->certificate();
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $this->user( 5 ) );
		$this->service->shouldReceive( 'find_by_uuid' )->andReturn( $cert );

		$res  = $this->controller->fetch_one( $this->request( [ 'uuid' => $cert->uuid ] ) );
		$body = $res->get_data();

		self::assertSame( 200, $res->status );
		self::assertSame( $cert->uuid, $body['data']['uuid'] );
		// Namespace-relative on purpose: the frontend joins this onto an API
		// base that already ends in `/wp-json`.
		self::assertSame( '/vl/v1/certificates/' . $cert->uuid . '/download', $body['data']['download_url'] );
		self::assertSame( 'https://example.test/certificates/' . $cert->uuid, $body['data']['verification_url'] );
	}

	public function test_fetch_one_returns_404_for_missing_uuid(): void {
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $this->user( 5 ) );
		$this->service->shouldReceive( 'find_by_uuid' )->andReturn( null );

		$res = $this->controller->fetch_one( $this->request( [ 'uuid' => 'missing' ] ) );

		self::assertInstanceOf( WP_Error::class, $res );
		self::assertSame( 'certificate_not_found', $res->get_error_code() );
		self::assertSame( 404, $res->get_error_data()['status'] );
	}

	public function test_fetch_one_returns_403_for_non_owner(): void {
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $this->user( 99 ) );
		$this->service->shouldReceive( 'find_by_uuid' )->andReturn( $this->certificate( '8e2c4d2a-0000-4000-8000-000000000001', 5 ) );

		$res = $this->controller->fetch_one( $this->request( [ 'uuid' => 'whatever' ] ) );

		self::assertInstanceOf( WP_Error::class, $res );
		self::assertSame( 'forbidden', $res->get_error_code() );
	}

	public function test_download_renders_pdf_and_streams(): void {
		$cert = $this->certificate();

		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $this->user( 5 ) );
		$this->service->shouldReceive( 'find_by_uuid' )->andReturn( $cert );
		$this->pdf->shouldReceive( 'generate' )
			->once()
			->andReturn( new GeneratedPdf( '/tmp/abs.pdf', 'certificates/x.pdf', false ) );
		$this->repo->shouldReceive( 'update_pdf_path' )->once()->with( $cert->id, 'certificates/x.pdf' );

		// stream_pdf() exits before `rest_pre_serve_request`, so the vl-cors
		// mu-plugin must be asked to emit CORS headers explicitly — without
		// this action the browser discards the streamed PDF.
		Actions\expectDone( 'vl_cors/emit_headers' )->once();

		$res = $this->controller->download( $this->request( [ 'uuid' => $cert->uuid ] ) );

		self::assertNull( $res );
		self::assertSame( '/tmp/abs.pdf', $this->controller->streamed_path );
		self::assertSame( $cert->uuid, $this->controller->streamed_uuid );
	}

	public function test_download_skips_repo_update_on_cache_hit(): void {
		$cert = $this->certificate();

		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $this->user( 5 ) );
		$this->service->shouldReceive( 'find_by_uuid' )->andReturn( $cert );
		$this->pdf->shouldReceive( 'generate' )
			->andReturn( new GeneratedPdf( '/tmp/abs.pdf', 'certificates/x.pdf', true ) );
		$this->repo->shouldNotReceive( 'update_pdf_path' );

		$this->controller->download( $this->request( [ 'uuid' => $cert->uuid ] ) );
	}

	public function test_download_returns_410_for_revoked_certificate(): void {
		$cert = $this->certificate( '8e2c4d2a-0000-4000-8000-000000000001', 5, $this->now );

		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $this->user( 5 ) );
		$this->service->shouldReceive( 'find_by_uuid' )->andReturn( $cert );
		$this->pdf->shouldNotReceive( 'generate' );

		$res = $this->controller->download( $this->request( [ 'uuid' => $cert->uuid ] ) );

		self::assertInstanceOf( WP_Error::class, $res );
		self::assertSame( 'certificate_revoked', $res->get_error_code() );
		self::assertSame( 410, $res->get_error_data()['status'] );
		self::assertArrayHasKey( 'revoked_at', $res->get_error_data() );
	}

	public function test_download_returns_404_for_missing_uuid(): void {
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $this->user( 5 ) );
		$this->service->shouldReceive( 'find_by_uuid' )->andReturn( null );

		$res = $this->controller->download( $this->request( [ 'uuid' => 'missing' ] ) );

		self::assertInstanceOf( WP_Error::class, $res );
		self::assertSame( 'certificate_not_found', $res->get_error_code() );
	}

	public function test_download_returns_500_when_pdf_generation_throws(): void {
		$cert = $this->certificate();

		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $this->user( 5 ) );
		$this->service->shouldReceive( 'find_by_uuid' )->andReturn( $cert );
		$this->pdf->shouldReceive( 'generate' )->andThrow( new \RuntimeException( 'boom' ) );

		$this->logger->shouldReceive( 'error' )->once();

		$res = $this->controller->download( $this->request( [ 'uuid' => $cert->uuid ] ) );

		self::assertInstanceOf( WP_Error::class, $res );
		self::assertSame( 'internal_error', $res->get_error_code() );
		self::assertSame( 500, $res->get_error_data()['status'] );
	}

	public function test_unauthed_returns_401(): void {
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( null );

		$res = $this->controller->list_mine( $this->request() );

		self::assertInstanceOf( WP_Error::class, $res );
		self::assertSame( 'unauthenticated', $res->get_error_code() );
	}

	public function test_permission_callback_denies_when_user_lacks_cap(): void {
		$user = $this->user( 5 );
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $user );

		Functions\when( 'user_can' )->alias(
			static fn ( WP_User $u, string $cap ): bool => 'vl_view_certificate' !== $cap
		);

		self::assertFalse( $this->controller->permission_callback( $this->request() ) );
	}

	public function test_permission_callback_allows_user_with_cap(): void {
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $this->user( 5 ) );

		self::assertTrue( $this->controller->permission_callback( $this->request() ) );
	}
}
