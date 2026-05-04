<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Api\Transformers;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Api\Transformers\WebinarRegistrationTransformer;
use VL\LMS\Catalog\Transformers\CoverImageTransformer;
use VL\LMS\Domain\WebinarRegistration\WebinarRegistration;
use VL\LMS\Domain\WebinarRegistration\WebinarRegistrationSource;
use VL\LMS\Domain\WebinarRegistration\WebinarRegistrationStatus;
use VL\LMS\Services\Webinars\WebinarAccessDecision;
use VL\LMS\Services\Webinars\WebinarAccessGate;
use VL\LMS\Services\Webinars\WebinarAccessReason;
use WP_Post;

final class WebinarRegistrationTransformerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface&WebinarAccessGate */
	private $gate;

	/** @var array<string, array<int, mixed>> */
	private array $meta = [];

	/** @var array<int, array<string, array{0: string, 1: int, 2: int, 3: bool}>> */
	private array $attachments = [];

	private WebinarRegistrationTransformer $transformer;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'get_post_meta' )->alias(
			fn ( int $post_id, string $key ): mixed => $this->meta[ $key ][ $post_id ] ?? ''
		);
		Functions\when( 'get_the_title' )->alias(
			static fn ( WP_Post $p ): string => (string) $p->post_title
		);
		Functions\when( 'wp_strip_all_tags' )->returnArg();
		Functions\when( 'get_post' )->justReturn( null );
		Functions\when( 'wp_get_attachment_image_src' )->alias(
			fn ( int $id, string $size ): array|false => $this->attachments[ $id ][ $size ] ?? false
		);

		$this->gate        = Mockery::mock( WebinarAccessGate::class );
		$this->transformer = new WebinarRegistrationTransformer( $this->gate, new CoverImageTransformer() );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function webinar( int $id ): WP_Post {
		$p              = Mockery::mock( 'WP_Post' );
		$p->ID          = $id;
		$p->post_name   = 'vet-may';
		$p->post_title  = 'Vet Clinical Update May 2026';
		$p->post_type   = 'vl_webinar';
		$p->post_status = 'publish';
		return $p;
	}

	private function registration( WebinarRegistrationStatus $status = WebinarRegistrationStatus::ACTIVE ): WebinarRegistration {
		return new WebinarRegistration(
			id: 42,
			webinar_id: 100,
			user_id: 5,
			status: $status,
			source: WebinarRegistrationSource::SELF_SIGNUP,
			registered_at: '2026-05-01 10:30:00',
			cancelled_at: WebinarRegistrationStatus::CANCELLED === $status ? '2026-05-02 12:00:00' : null,
			attended: false,
			attended_duration_seconds: 0,
			created_at: '2026-05-01 10:30:00',
			updated_at: '2026-05-01 10:30:00'
		);
	}

	public function test_transforms_active_upcoming_registration_with_full_payload(): void {
		$post       = $this->webinar( 100 );
		$this->meta = [
			'_vl_webinar_scheduled_start'           => [ 100 => '2026-05-15T18:00:00Z' ],
			'_vl_webinar_scheduled_end'             => [ 100 => '2026-05-15T19:30:00Z' ],
			'_vl_webinar_registration_opens_at'     => [ 100 => '2026-04-01T00:00:00Z' ],
			'_vl_webinar_registration_closes_at'    => [ 100 => '2026-05-15T17:00:00Z' ],
			'_vl_webinar_price'                     => [ 100 => 0.0 ],
			'_vl_webinar_currency'                  => [ 100 => 'UAH' ],
			'_vl_webinar_max_attendees'             => [ 100 => 200 ],
			'_vl_webinar_cover_image_id'            => [ 100 => 99 ],
			'_vl_webinar_status'                    => [ 100 => 'scheduled' ],
			'_vl_webinar_recording_access_days'     => [ 100 => 30 ],
			'_vl_webinar_recording_available_until' => [ 100 => '' ],
		];
		Functions\when( 'get_post' )->alias(
			static fn ( int $id ): ?WP_Post => 99 === $id ? Mockery::mock( 'WP_Post' ) : null
		);
		$this->attachments = [
			99 => [
				'medium_large' => [ 'https://t/card.jpg', 100, 100, true ],
			],
		];
		$this->gate->shouldReceive( 'can_join' )
			->andReturn( WebinarAccessDecision::deny( WebinarAccessReason::JOIN_WINDOW_NOT_OPEN ) );
		$this->gate->shouldReceive( 'can_view_recording' )
			->andReturn( WebinarAccessDecision::deny( WebinarAccessReason::RECORDING_NOT_AVAILABLE ) );

		$now = new \DateTimeImmutable( '2026-05-10T00:00:00Z' );
		$out = $this->transformer->transform( $this->registration(), $post, $now );

		self::assertSame( 42, $out['id'] );
		self::assertSame( 'active', $out['status'] );
		self::assertSame( 'self_signup', $out['source'] );
		self::assertSame( '2026-05-01T10:30:00Z', $out['registered_at'] );
		self::assertNull( $out['cancelled_at'] );
		self::assertFalse( $out['attended'] );
		self::assertSame( 100, $out['webinar']['id'] );
		self::assertSame( 'vet-may', $out['webinar']['slug'] );
		self::assertSame( 'Vet Clinical Update May 2026', $out['webinar']['title'] );
		self::assertSame( '2026-05-15T18:00:00Z', $out['webinar']['scheduled_start'] );
		self::assertSame( 0.0, $out['webinar']['price']['amount'] );
		self::assertSame( 'UAH', $out['webinar']['price']['currency'] );
		self::assertSame( 200, $out['webinar']['capacity'] );
		self::assertSame( 30, $out['webinar']['recording_access_days'] );
		self::assertNull( $out['webinar']['recording_available_until'] );
		self::assertSame( 'https://t/card.jpg', $out['webinar']['cover']['card']['url'] );
		self::assertFalse( $out['computed']['join_window_open'] );
		self::assertFalse( $out['computed']['recording_available'] );
		self::assertFalse( $out['computed']['is_past'] );
		self::assertSame( '2026-05-15T17:45:00+00:00', $out['computed']['join_opens_at'] );
		self::assertSame( '2026-05-15T20:30:00+00:00', $out['computed']['join_closes_at'] );
	}

	public function test_marks_join_window_open_when_gate_allows_join(): void {
		$post       = $this->webinar( 100 );
		$this->meta = [
			'_vl_webinar_scheduled_start' => [ 100 => '2026-05-15T18:00:00Z' ],
			'_vl_webinar_scheduled_end'   => [ 100 => '2026-05-15T19:30:00Z' ],
		];
		$this->gate->shouldReceive( 'can_join' )
			->andReturn( WebinarAccessDecision::allow( 'https://zoom.us/j/x' ) );
		$this->gate->shouldReceive( 'can_view_recording' )
			->andReturn( WebinarAccessDecision::deny( WebinarAccessReason::RECORDING_NOT_AVAILABLE ) );

		$now = new \DateTimeImmutable( '2026-05-15T18:00:00Z' );
		$out = $this->transformer->transform( $this->registration(), $post, $now );

		self::assertTrue( $out['computed']['join_window_open'] );
	}

	public function test_marks_recording_available_when_gate_allows_recording(): void {
		$post       = $this->webinar( 100 );
		$this->meta = [
			'_vl_webinar_scheduled_start' => [ 100 => '2026-05-15T18:00:00Z' ],
			'_vl_webinar_scheduled_end'   => [ 100 => '2026-05-15T19:30:00Z' ],
		];
		$this->gate->shouldReceive( 'can_join' )
			->andReturn( WebinarAccessDecision::deny( WebinarAccessReason::JOIN_WINDOW_CLOSED ) );
		$this->gate->shouldReceive( 'can_view_recording' )
			->andReturn( WebinarAccessDecision::allow( 'https://zoom.us/r/x' ) );

		$now = new \DateTimeImmutable( '2026-06-01T00:00:00Z' );
		$out = $this->transformer->transform( $this->registration(), $post, $now );

		self::assertTrue( $out['computed']['recording_available'] );
		self::assertTrue( $out['computed']['is_past'] );
	}

	public function test_serializes_cancelled_registration_with_cancelled_at(): void {
		$post = $this->webinar( 100 );
		$this->gate->shouldReceive( 'can_join' )
			->andReturn( WebinarAccessDecision::deny( WebinarAccessReason::NOT_REGISTERED ) );
		$this->gate->shouldReceive( 'can_view_recording' )
			->andReturn( WebinarAccessDecision::deny( WebinarAccessReason::NOT_REGISTERED ) );

		$now = new \DateTimeImmutable( '2026-05-10T00:00:00Z' );
		$out = $this->transformer->transform(
			$this->registration( WebinarRegistrationStatus::CANCELLED ),
			$post,
			$now
		);

		self::assertSame( 'cancelled', $out['status'] );
		self::assertSame( '2026-05-02T12:00:00Z', $out['cancelled_at'] );
	}

	public function test_emits_null_cover_when_no_attachment(): void {
		$post       = $this->webinar( 100 );
		$this->meta = [ '_vl_webinar_cover_image_id' => [ 100 => 0 ] ];
		$this->gate->shouldReceive( 'can_join' )
			->andReturn( WebinarAccessDecision::deny( WebinarAccessReason::NOT_REGISTERED ) );
		$this->gate->shouldReceive( 'can_view_recording' )
			->andReturn( WebinarAccessDecision::deny( WebinarAccessReason::NOT_REGISTERED ) );

		$out = $this->transformer->transform(
			$this->registration(),
			$post,
			new \DateTimeImmutable( '2026-05-01T00:00:00Z' )
		);

		self::assertNull( $out['webinar']['cover'] );
	}
}
