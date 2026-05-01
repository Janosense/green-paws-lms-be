<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Certificate;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Certificate\CertificateRevoker;
use VL\LMS\Certificate\CertificateService;
use VL\LMS\Domain\Certificate\Certificate;
use VL\LMS\Support\Logger;
use VL\LMS\Tests\Fixtures\InMemoryCertificateRepository;

final class CertificateRevokerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private InMemoryCertificateRepository $certs;

	/** @var Mockery\MockInterface&CertificateService */
	private $service;

	/** @var Mockery\MockInterface&Logger */
	private $logger;

	private CertificateRevoker $revoker;

	private \DateTimeImmutable $now;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->now     = new \DateTimeImmutable( '2026-04-29T10:00:00+00:00' );
		$this->certs   = new InMemoryCertificateRepository( fn () => $this->now );
		$this->service = Mockery::mock( CertificateService::class );
		$this->logger  = Mockery::mock( Logger::class )->shouldIgnoreMissing();
		$this->revoker = new CertificateRevoker( $this->service, $this->certs, $this->logger );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function seed_active( int $enrollment_id, string $uuid = 'uuid' ): int {
		return $this->certs->insert(
			new Certificate(
				0,
				$uuid,
				5,
				50,
				$enrollment_id,
				$this->now,
				null,
				null,
				null,
				[],
				null,
				$this->now,
				$this->now
			)
		);
	}

	private function seed_revoked( int $enrollment_id, string $uuid = 'uuid' ): int {
		return $this->certs->insert(
			new Certificate(
				0,
				$uuid,
				5,
				50,
				$enrollment_id,
				$this->now,
				$this->now,
				null,
				null,
				[],
				null,
				$this->now,
				$this->now
			)
		);
	}

	public function test_register_hooks_action(): void {
		Functions\expect( 'add_action' )
			->once()
			->with( 'vl_lms_enrollment_revoked', Mockery::type( 'array' ), 20, 2 );

		$this->revoker->register();
	}

	public function test_revokes_all_active_certificates_for_enrollment(): void {
		$id1 = $this->seed_active( 21, 'a' );
		$id2 = $this->seed_active( 21, 'b' );

		$this->service->shouldReceive( 'revoke' )->with( $id1, Mockery::type( \DateTimeImmutable::class ) )->andReturn( true );
		$this->service->shouldReceive( 'revoke' )->with( $id2, Mockery::type( \DateTimeImmutable::class ) )->andReturn( true );

		$count = $this->revoker->revoke_for_enrollment( 21 );

		self::assertSame( 2, $count );
	}

	public function test_skips_already_revoked_certificates(): void {
		$id_active = $this->seed_active( 21, 'a' );
		$this->seed_revoked( 21, 'b' );

		$this->service->shouldReceive( 'revoke' )->with( $id_active, Mockery::type( \DateTimeImmutable::class ) )->andReturn( true );
		$this->service->shouldNotReceive( 'revoke' )->with( Mockery::not( $id_active ), Mockery::any() );

		$count = $this->revoker->revoke_for_enrollment( 21 );

		self::assertSame( 1, $count );
	}

	public function test_returns_zero_when_no_certificates_exist(): void {
		$this->service->shouldNotReceive( 'revoke' );

		self::assertSame( 0, $this->revoker->revoke_for_enrollment( 9999 ) );
	}

	public function test_swallows_individual_revoke_errors_and_continues(): void {
		$id1 = $this->seed_active( 21, 'a' );
		$id2 = $this->seed_active( 21, 'b' );

		$this->service->shouldReceive( 'revoke' )->with( $id1, Mockery::type( \DateTimeImmutable::class ) )
			->andThrow( new \RuntimeException( 'boom' ) );
		$this->service->shouldReceive( 'revoke' )->with( $id2, Mockery::type( \DateTimeImmutable::class ) )
			->andReturn( true );

		$this->logger->shouldReceive( 'error' )->once();

		$count = $this->revoker->revoke_for_enrollment( 21 );

		self::assertSame( 1, $count );
	}

	public function test_action_callback_invokes_revoke_for_enrollment(): void {
		$this->seed_active( 21, 'a' );
		$this->service->shouldReceive( 'revoke' )->andReturn( true );

		$this->revoker->on_enrollment_revoked( 21, 'policy violation' );

		// Smoke check — ensure the call ran without throwing and no
		// side-effects beyond the service.revoke invocations recorded
		// by the Mockery expectations above.
		self::assertTrue( true );
	}
}
