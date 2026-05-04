<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Services\Webinars;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\WebinarRegistration\WebinarRegistration;
use VL\LMS\Domain\WebinarRegistration\WebinarRegistrationSource;
use VL\LMS\Domain\WebinarRegistration\WebinarRegistrationStatus;
use VL\LMS\Repositories\WebinarRegistrationRepository;
use VL\LMS\Services\Webinars\WebinarLookup;
use VL\LMS\Services\Webinars\WebinarRegistrationDecisionType;
use VL\LMS\Services\Webinars\WebinarRegistrationError;
use VL\LMS\Services\Webinars\WebinarRegistrationService;
use WP_Post;

final class WebinarRegistrationServiceTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface&WebinarLookup */
	private $lookup;

	/** @var Mockery\MockInterface&WebinarRegistrationRepository */
	private $repository;

	private \DateTimeImmutable $now;

	private WebinarRegistrationService $service;

	/** @var array<string, array<int, mixed>> */
	private array $meta = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'get_post_meta' )->alias(
			fn ( int $post_id, string $key ): mixed => $this->meta[ $key ][ $post_id ] ?? ''
		);

		$this->lookup     = Mockery::mock( WebinarLookup::class );
		$this->repository = Mockery::mock( WebinarRegistrationRepository::class );
		$this->now        = new \DateTimeImmutable( '2026-05-01T12:00:00Z' );

		$this->service = new WebinarRegistrationService(
			$this->lookup,
			$this->repository,
			fn (): \DateTimeImmutable => $this->now
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function webinar( int $id, string $slug = 'vet-may', string $status = 'publish' ): WP_Post {
		$p              = Mockery::mock( 'WP_Post' );
		$p->ID          = $id;
		$p->post_name   = $slug;
		$p->post_type   = 'vl_webinar';
		$p->post_status = $status;
		return $p;
	}

	private function registration(
		int $id,
		int $webinar_id,
		int $user_id,
		WebinarRegistrationStatus $status = WebinarRegistrationStatus::ACTIVE
	): WebinarRegistration {
		return new WebinarRegistration(
			id: $id,
			webinar_id: $webinar_id,
			user_id: $user_id,
			status: $status,
			source: WebinarRegistrationSource::SELF_SIGNUP,
			registered_at: '2026-05-01 12:00:00',
			cancelled_at: WebinarRegistrationStatus::CANCELLED === $status ? '2026-05-01 12:30:00' : null,
			attended: false,
			attended_duration_seconds: 0,
			created_at: '2026-05-01 12:00:00',
			updated_at: '2026-05-01 12:00:00'
		);
	}

	public function test_register_returns_webinar_not_found_when_lookup_returns_null(): void {
		$this->lookup->shouldReceive( 'find_by_slug' )->with( 'missing' )->andReturn( null );

		$decision = $this->service->register( 5, 'missing', WebinarRegistrationSource::SELF_SIGNUP );

		self::assertSame( WebinarRegistrationDecisionType::FAILED, $decision->decision );
		self::assertSame( WebinarRegistrationError::WEBINAR_NOT_FOUND, $decision->error );
	}

	public function test_register_returns_not_published_when_post_status_changes_under_us(): void {
		$post = $this->webinar( 100, status: 'draft' );
		$this->lookup->shouldReceive( 'find_by_slug' )->andReturn( $post );

		$decision = $this->service->register( 5, 'vet-may', WebinarRegistrationSource::SELF_SIGNUP );

		self::assertSame( WebinarRegistrationError::NOT_PUBLISHED, $decision->error );
	}

	public function test_register_fails_when_registration_window_has_not_opened(): void {
		$post = $this->webinar( 100 );
		$this->lookup->shouldReceive( 'find_by_slug' )->andReturn( $post );
		$this->meta = [
			'_vl_webinar_registration_opens_at' => [ 100 => '2026-06-01T00:00:00Z' ],
		];

		$decision = $this->service->register( 5, 'vet-may', WebinarRegistrationSource::SELF_SIGNUP );

		self::assertSame( WebinarRegistrationError::REGISTRATION_NOT_OPEN_YET, $decision->error );
		self::assertSame( '2026-06-01T00:00:00Z', $decision->context['opens_at'] );
	}

	public function test_register_fails_when_registration_window_has_closed(): void {
		$post = $this->webinar( 100 );
		$this->lookup->shouldReceive( 'find_by_slug' )->andReturn( $post );
		$this->meta = [
			'_vl_webinar_registration_closes_at' => [ 100 => '2026-04-01T00:00:00Z' ],
		];

		$decision = $this->service->register( 5, 'vet-may', WebinarRegistrationSource::SELF_SIGNUP );

		self::assertSame( WebinarRegistrationError::REGISTRATION_CLOSED, $decision->error );
		self::assertSame( '2026-04-01T00:00:00Z', $decision->context['closes_at'] );
	}

	public function test_register_returns_payment_required_for_paid_webinar(): void {
		$post = $this->webinar( 100 );
		$this->lookup->shouldReceive( 'find_by_slug' )->andReturn( $post );
		$this->meta = [
			'_vl_webinar_price'    => [ 100 => 499.0 ],
			'_vl_webinar_currency' => [ 100 => 'UAH' ],
		];

		$decision = $this->service->register( 5, 'vet-may', WebinarRegistrationSource::SELF_SIGNUP );

		self::assertSame( WebinarRegistrationError::PAYMENT_REQUIRED, $decision->error );
		self::assertSame( 499.0, $decision->context['price']['amount'] );
		self::assertSame( 'UAH', $decision->context['price']['currency'] );
	}

	public function test_register_returns_capacity_reached_when_full_and_user_not_active(): void {
		$post = $this->webinar( 100 );
		$this->lookup->shouldReceive( 'find_by_slug' )->andReturn( $post );
		$this->meta = [
			'_vl_webinar_max_attendees' => [ 100 => 50 ],
		];
		$this->repository->shouldReceive( 'count_active_for_webinar' )->with( 100 )->andReturn( 50 );
		$this->repository->shouldReceive( 'find' )->with( 100, 5 )->andReturn( null );

		$decision = $this->service->register( 5, 'vet-may', WebinarRegistrationSource::SELF_SIGNUP );

		self::assertSame( WebinarRegistrationError::CAPACITY_REACHED, $decision->error );
		self::assertSame( 50, $decision->context['capacity'] );
	}

	public function test_register_does_not_block_already_active_user_when_capacity_reached(): void {
		$post = $this->webinar( 100 );
		$this->lookup->shouldReceive( 'find_by_slug' )->andReturn( $post );
		$this->meta = [
			'_vl_webinar_max_attendees' => [ 100 => 50 ],
		];
		$existing   = $this->registration( 1, 100, 5 );
		$this->repository->shouldReceive( 'count_active_for_webinar' )->with( 100 )->andReturn( 50 );
		$this->repository->shouldReceive( 'find' )->with( 100, 5 )->andReturn( $existing );

		$decision = $this->service->register( 5, 'vet-may', WebinarRegistrationSource::SELF_SIGNUP );

		self::assertSame( WebinarRegistrationDecisionType::ALREADY_ACTIVE, $decision->decision );
		self::assertSame( $existing, $decision->registration );
	}

	public function test_register_inserts_new_row_when_no_prior_registration(): void {
		$post = $this->webinar( 100 );
		$this->lookup->shouldReceive( 'find_by_slug' )->andReturn( $post );
		$this->repository->shouldReceive( 'find' )->with( 100, 5 )->andReturn( null );
		$persisted = $this->registration( 9, 100, 5 );
		$this->repository->shouldReceive( 'register' )
			->once()
			->with( 100, 5, WebinarRegistrationSource::SELF_SIGNUP, $this->now )
			->andReturn( $persisted );

		$decision = $this->service->register( 5, 'vet-may', WebinarRegistrationSource::SELF_SIGNUP );

		self::assertSame( WebinarRegistrationDecisionType::REGISTERED, $decision->decision );
		self::assertSame( $persisted, $decision->registration );
	}

	public function test_register_re_registers_when_prior_row_is_cancelled(): void {
		$post = $this->webinar( 100 );
		$this->lookup->shouldReceive( 'find_by_slug' )->andReturn( $post );
		$prior = $this->registration( 9, 100, 5, WebinarRegistrationStatus::CANCELLED );
		$this->repository->shouldReceive( 'find' )->with( 100, 5 )->andReturn( $prior );
		$persisted = $this->registration( 9, 100, 5 );
		$this->repository->shouldReceive( 'register' )
			->once()
			->andReturn( $persisted );

		$decision = $this->service->register( 5, 'vet-may', WebinarRegistrationSource::SELF_SIGNUP );

		self::assertSame( WebinarRegistrationDecisionType::RE_REGISTERED, $decision->decision );
	}

	public function test_register_returns_already_active_idempotent_when_active_row_exists(): void {
		$post = $this->webinar( 100 );
		$this->lookup->shouldReceive( 'find_by_slug' )->andReturn( $post );
		$prior = $this->registration( 9, 100, 5 );
		$this->repository->shouldReceive( 'find' )->with( 100, 5 )->andReturn( $prior );
		$this->repository->shouldNotReceive( 'register' );

		$decision = $this->service->register( 5, 'vet-may', WebinarRegistrationSource::SELF_SIGNUP );

		self::assertSame( WebinarRegistrationDecisionType::ALREADY_ACTIVE, $decision->decision );
		self::assertSame( $prior, $decision->registration );
	}

	public function test_cancel_returns_webinar_not_found_when_lookup_misses(): void {
		$this->lookup->shouldReceive( 'find_by_slug' )->andReturn( null );

		$decision = $this->service->cancel( 5, 'vet-may' );

		self::assertSame( WebinarRegistrationError::WEBINAR_NOT_FOUND, $decision->error );
	}

	public function test_cancel_returns_not_registered_when_no_row(): void {
		$post = $this->webinar( 100 );
		$this->lookup->shouldReceive( 'find_by_slug' )->andReturn( $post );
		$this->repository->shouldReceive( 'find' )->with( 100, 5 )->andReturn( null );

		$decision = $this->service->cancel( 5, 'vet-may' );

		self::assertSame( WebinarRegistrationError::NOT_REGISTERED, $decision->error );
	}

	public function test_cancel_is_idempotent_for_already_cancelled_row(): void {
		$post = $this->webinar( 100 );
		$this->lookup->shouldReceive( 'find_by_slug' )->andReturn( $post );
		$prior = $this->registration( 9, 100, 5, WebinarRegistrationStatus::CANCELLED );
		$this->repository->shouldReceive( 'find' )->with( 100, 5 )->andReturn( $prior );
		$this->repository->shouldNotReceive( 'cancel' );

		$decision = $this->service->cancel( 5, 'vet-may' );

		self::assertSame( WebinarRegistrationDecisionType::ALREADY_CANCELLED, $decision->decision );
		self::assertSame( $prior, $decision->registration );
	}

	public function test_cancel_flips_active_row_to_cancelled(): void {
		$post = $this->webinar( 100 );
		$this->lookup->shouldReceive( 'find_by_slug' )->andReturn( $post );
		$prior = $this->registration( 9, 100, 5 );
		$this->repository->shouldReceive( 'find' )->with( 100, 5 )->andReturn( $prior );
		$cancelled = $this->registration( 9, 100, 5, WebinarRegistrationStatus::CANCELLED );
		$this->repository->shouldReceive( 'cancel' )
			->once()
			->with( 100, 5, $this->now )
			->andReturn( $cancelled );

		$decision = $this->service->cancel( 5, 'vet-may' );

		self::assertSame( WebinarRegistrationDecisionType::CANCELLED, $decision->decision );
		self::assertSame( $cancelled, $decision->registration );
	}

	public function test_has_active_registration_returns_true_when_active_row_exists(): void {
		$prior = $this->registration( 9, 100, 5 );
		$this->repository->shouldReceive( 'find_active' )
			->with( 100, 5 )
			->andReturn( $prior );

		self::assertTrue( $this->service->has_active_registration( 5, 100 ) );
	}

	public function test_has_active_registration_returns_false_when_no_active_row(): void {
		$this->repository->shouldReceive( 'find_active' )
			->with( 100, 5 )
			->andReturn( null );

		self::assertFalse( $this->service->has_active_registration( 5, 100 ) );
	}

	public function test_has_capacity_returns_true_for_unlimited_capacity(): void {
		$this->meta = [];
		$this->repository->shouldNotReceive( 'count_active_for_webinar' );

		self::assertTrue( $this->service->has_capacity( 100 ) );
	}

	public function test_has_capacity_returns_true_when_under_limit(): void {
		$this->meta['_vl_webinar_max_attendees'] = [ 100 => '50' ];
		$this->repository->shouldReceive( 'count_active_for_webinar' )
			->with( 100 )
			->andReturn( 49 );

		self::assertTrue( $this->service->has_capacity( 100 ) );
	}

	public function test_has_capacity_returns_false_when_at_limit(): void {
		$this->meta['_vl_webinar_max_attendees'] = [ 100 => '50' ];
		$this->repository->shouldReceive( 'count_active_for_webinar' )
			->with( 100 )
			->andReturn( 50 );

		self::assertFalse( $this->service->has_capacity( 100 ) );
	}
}
