<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Catalog\Detail;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Catalog\Detail\RegistrationWindow;

final class RegistrationWindowTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private RegistrationWindow $window;

	/** @var array<string, array<int, mixed>> */
	private array $meta = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'get_post_meta' )->alias(
			fn ( int $post_id, string $key ): mixed => $this->meta[ $key ][ $post_id ] ?? ''
		);

		$this->window = new RegistrationWindow();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_returns_true_inside_window(): void {
		$now    = time();
		$past   = gmdate( 'Y-m-d\TH:i:s\Z', $now - 86400 );
		$future = gmdate( 'Y-m-d\TH:i:s\Z', $now + 86400 );

		$this->meta = [
			'_vl_webinar_registration_opens_at'  => [ 200 => $past ],
			'_vl_webinar_registration_closes_at' => [ 200 => $future ],
		];

		self::assertTrue( $this->window->is_open( 200 ) );
	}

	public function test_returns_false_before_window(): void {
		$future = gmdate( 'Y-m-d\TH:i:s\Z', time() + 86400 );

		$this->meta = [
			'_vl_webinar_registration_opens_at' => [ 200 => $future ],
		];

		self::assertFalse( $this->window->is_open( 200 ) );
	}

	public function test_returns_false_after_window(): void {
		$past = gmdate( 'Y-m-d\TH:i:s\Z', time() - 86400 );

		$this->meta = [
			'_vl_webinar_registration_closes_at' => [ 200 => $past ],
		];

		self::assertFalse( $this->window->is_open( 200 ) );
	}

	public function test_returns_true_when_both_bounds_unset(): void {
		// Missing meta = unbounded both sides — registration is open.
		self::assertTrue( $this->window->is_open( 200 ) );
	}

	public function test_returns_false_when_opens_at_is_unparseable(): void {
		$this->meta = [
			'_vl_webinar_registration_opens_at' => [ 200 => 'not-a-date' ],
		];

		self::assertFalse( $this->window->is_open( 200 ) );
	}

	public function test_returns_false_when_closes_at_is_unparseable(): void {
		$this->meta = [
			'_vl_webinar_registration_closes_at' => [ 200 => 'not-a-date' ],
		];

		self::assertFalse( $this->window->is_open( 200 ) );
	}

	public function test_open_with_only_past_opens_at_set(): void {
		$past = gmdate( 'Y-m-d\TH:i:s\Z', time() - 86400 );

		$this->meta = [
			'_vl_webinar_registration_opens_at' => [ 200 => $past ],
		];

		self::assertTrue( $this->window->is_open( 200 ) );
	}

	public function test_open_with_only_future_closes_at_set(): void {
		$future = gmdate( 'Y-m-d\TH:i:s\Z', time() + 86400 );

		$this->meta = [
			'_vl_webinar_registration_closes_at' => [ 200 => $future ],
		];

		self::assertTrue( $this->window->is_open( 200 ) );
	}
}
