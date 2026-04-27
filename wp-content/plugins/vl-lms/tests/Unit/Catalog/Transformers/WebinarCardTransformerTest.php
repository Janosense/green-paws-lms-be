<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Catalog\Transformers;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Catalog\Detail\RegistrationWindow;
use VL\LMS\Catalog\TaxonomyTermTransformer;
use VL\LMS\Catalog\Transformers\CoverImageTransformer;
use VL\LMS\Catalog\Transformers\LeadInstructorTransformer;
use VL\LMS\Catalog\Transformers\WebinarCardTransformer;
use WP_Post;

final class WebinarCardTransformerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private WebinarCardTransformer $transformer;

	/** @var array<string, array<int, mixed>> */
	private array $meta = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'get_the_title' )->alias(
			static fn ( WP_Post $p ): string => (string) $p->post_title
		);
		Functions\when( 'get_the_excerpt' )->alias(
			static fn ( WP_Post $p ): string => (string) $p->post_excerpt
		);
		Functions\when( 'wp_strip_all_tags' )->returnArg();
		Functions\when( 'get_post_meta' )->alias(
			fn ( int $post_id, string $key ): mixed => $this->meta[ $key ][ $post_id ] ?? ''
		);
		Functions\when( 'get_post' )->justReturn( null );
		Functions\when( 'wp_get_attachment_image_src' )->justReturn( false );

		$this->transformer = new WebinarCardTransformer(
			new CoverImageTransformer(),
			new LeadInstructorTransformer(),
			new TaxonomyTermTransformer(),
			new RegistrationWindow(),
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_card_emits_scheduled_dates_status_and_currency(): void {
		$this->meta = [
			'_vl_webinar_scheduled_start' => [ 200 => '2026-06-01T10:00:00Z' ],
			'_vl_webinar_scheduled_end'   => [ 200 => '2026-06-01T12:00:00Z' ],
			'_vl_webinar_status'          => [ 200 => 'scheduled' ],
			'_vl_webinar_price'           => [ 200 => 750.0 ],
			'_vl_webinar_currency'        => [ 200 => 'UAH' ],
		];

		$card = $this->transformer->transform(
			$this->post( 200, 'spring-cardiology', 'Spring Cardiology', '' ),
			null,
			[],
		);

		self::assertSame( '2026-06-01T10:00:00Z', $card['scheduled_start'] );
		self::assertSame( '2026-06-01T12:00:00Z', $card['scheduled_end'] );
		self::assertSame( 'scheduled', $card['status'] );
		self::assertSame( 750.0, $card['price'] );
		self::assertSame( 'UAH', $card['currency'] );
		self::assertSame( '/webinars/spring-cardiology', $card['permalink'] );
	}

	public function test_registration_open_true_inside_window(): void {
		$now    = time();
		$past   = gmdate( 'Y-m-d\TH:i:s\Z', $now - 86400 );
		$future = gmdate( 'Y-m-d\TH:i:s\Z', $now + 86400 );

		$this->meta = [
			'_vl_webinar_registration_opens_at'  => [ 200 => $past ],
			'_vl_webinar_registration_closes_at' => [ 200 => $future ],
		];

		$card = $this->transformer->transform(
			$this->post( 200, 'w', 'W', '' ),
			null,
			[],
		);

		self::assertTrue( $card['registration_open'] );
	}

	public function test_registration_open_false_before_window(): void {
		$now    = time();
		$future = gmdate( 'Y-m-d\TH:i:s\Z', $now + 86400 );

		$this->meta = [
			'_vl_webinar_registration_opens_at' => [ 200 => $future ],
		];

		$card = $this->transformer->transform(
			$this->post( 200, 'w', 'W', '' ),
			null,
			[],
		);

		self::assertFalse( $card['registration_open'] );
	}

	public function test_registration_open_false_after_window(): void {
		$now  = time();
		$past = gmdate( 'Y-m-d\TH:i:s\Z', $now - 86400 );

		$this->meta = [
			'_vl_webinar_registration_closes_at' => [ 200 => $past ],
		];

		$card = $this->transformer->transform(
			$this->post( 200, 'w', 'W', '' ),
			null,
			[],
		);

		self::assertFalse( $card['registration_open'] );
	}

	public function test_registration_open_true_when_max_attendees_set_phase_7_capacity_bypassed(): void {
		// In Phase 3.1 the time-window is the only gate — the capacity stub
		// is intentionally bypassed (TODO Phase 7). This guards against an
		// accidental capacity-check regression slipping in early.
		$now    = time();
		$past   = gmdate( 'Y-m-d\TH:i:s\Z', $now - 86400 );
		$future = gmdate( 'Y-m-d\TH:i:s\Z', $now + 86400 );

		$this->meta = [
			'_vl_webinar_registration_opens_at'  => [ 200 => $past ],
			'_vl_webinar_registration_closes_at' => [ 200 => $future ],
			'_vl_webinar_max_attendees'          => [ 200 => 1 ],
		];

		$card = $this->transformer->transform(
			$this->post( 200, 'w', 'W', '' ),
			null,
			[],
		);

		self::assertTrue( $card['registration_open'] );
	}

	private function post( int $id, string $slug, string $title, string $excerpt ): WP_Post {
		$post               = Mockery::mock( 'WP_Post' );
		$post->ID           = $id;
		$post->post_name    = $slug;
		$post->post_title   = $title;
		$post->post_excerpt = $excerpt;
		$post->post_type    = 'vl_webinar';
		$post->post_status  = 'publish';
		return $post;
	}
}
