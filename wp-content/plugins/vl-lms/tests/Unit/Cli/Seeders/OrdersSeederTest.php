<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Cli\Seeders;

use Brain\Monkey;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Cli\SeederContext;
use VL\LMS\Cli\Seeders\OrdersSeeder;
use VL\LMS\Domain\Order\OrderStatus;
use VL\LMS\Tests\Fixtures\InMemoryOrderRepository;
use VL\LMS\Tests\Fixtures\InMemoryPaymentRepository;

final class OrdersSeederTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private InMemoryOrderRepository $orders;

	private InMemoryPaymentRepository $payments;

	private OrdersSeeder $seeder;

	/** @var list<string> */
	private array $log_lines = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->orders    = new InMemoryOrderRepository();
		$this->payments  = new InMemoryPaymentRepository();
		$this->log_lines = [];
		$this->seeder    = new OrdersSeeder( $this->orders, $this->payments );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function context(): SeederContext {
		return new SeederContext(
			environment_type: 'local',
			force: false,
			skip_progress: false,
			seed: 42,
			logger: function ( string $line ): void {
				$this->log_lines[] = $line;
			},
			skip_zoom: true
		);
	}

	/**
	 * @return array<int, array{slug: string, title: string}>
	 */
	private function entity_meta(): array {
		return [
			100 => [
				'slug'  => 'demo-course-a',
				'title' => 'Demo Course A',
			],
			101 => [
				'slug'  => 'demo-course-b',
				'title' => 'Demo Course B',
			],
			200 => [
				'slug'  => 'demo-webinar-a',
				'title' => 'Demo Webinar A',
			],
			201 => [
				'slug'  => 'demo-webinar-b',
				'title' => 'Demo Webinar B',
			],
		];
	}

	public function test_seed_creates_five_orders_in_mixed_states(): void {
		$result = $this->seeder->run(
			$this->context(),
			[ 'student.bohdan' => 7 ],
			[ 100, 101 ],
			[ 200, 201 ],
			$this->entity_meta()
		);

		self::assertSame( 5, $result->created );
		self::assertSame( 0, $result->skipped );
		self::assertSame( 0, $result->failed );

		$listed = $this->orders->list_for_admin( [], 1, 50, 'created_at', 'DESC' );
		self::assertSame( 5, $listed['total'] );

		$statuses = array_map(
			static fn ( $o ): string => $o->status->value,
			$listed['items']
		);
		sort( $statuses );

		self::assertSame(
			[
				OrderStatus::EXPIRED->value,
				OrderStatus::FAILED->value,
				OrderStatus::PAID->value,
				OrderStatus::PENDING->value,
				OrderStatus::REFUNDED->value,
			],
			$statuses
		);
	}

	public function test_seed_inserts_payment_audit_rows_for_paid_refunded_failed(): void {
		$this->seeder->run(
			$this->context(),
			[ 'student.bohdan' => 7 ],
			[ 100, 101 ],
			[ 200, 201 ],
			$this->entity_meta()
		);

		$listed             = $this->orders->list_for_admin( [], 1, 50, 'created_at', 'DESC' );
		$total_payment_rows = 0;
		foreach ( $listed['items'] as $order ) {
			$total_payment_rows += count( $this->payments->list_for_order( (int) $order->id ) );
		}

		// PAID: 1 charge ; REFUNDED: 1 charge + 1 refund ; FAILED: 1 charge ; EXPIRED: 0 ; PENDING: 0
		self::assertSame( 4, $total_payment_rows );
	}

	public function test_seed_is_idempotent_on_second_run(): void {
		$ctx = $this->context();
		$this->seeder->run( $ctx, [ 'student.bohdan' => 7 ], [ 100, 101 ], [ 200, 201 ], $this->entity_meta() );

		$second = $this->seeder->run( $ctx, [ 'student.bohdan' => 7 ], [ 100, 101 ], [ 200, 201 ], $this->entity_meta() );

		self::assertSame( 0, $second->created );
		self::assertSame( 1, $second->skipped );
	}

	public function test_seed_bails_out_when_no_demo_student(): void {
		$result = $this->seeder->run(
			$this->context(),
			[],
			[ 100, 101 ],
			[ 200, 201 ],
			$this->entity_meta()
		);

		self::assertSame( 0, $result->created );
		self::assertSame( 1, $result->failed );
	}

	public function test_seed_bails_out_when_missing_paid_entities(): void {
		$result = $this->seeder->run(
			$this->context(),
			[ 'student.bohdan' => 7 ],
			[ 100 ],
			[],
			$this->entity_meta()
		);

		self::assertSame( 0, $result->created );
		self::assertSame( 1, $result->failed );
	}

	public function test_demo_payment_rows_carry_demo_prefix(): void {
		$this->seeder->run(
			$this->context(),
			[ 'student.bohdan' => 7 ],
			[ 100, 101 ],
			[ 200, 201 ],
			$this->entity_meta()
		);

		$listed          = $this->orders->list_for_admin( [], 1, 50, 'created_at', 'DESC' );
		$saw_demo_prefix = false;
		foreach ( $listed['items'] as $order ) {
			foreach ( $this->payments->list_for_order( (int) $order->id ) as $payment ) {
				if ( null !== $payment->provider_payment_id && str_starts_with( $payment->provider_payment_id, 'demo-' ) ) {
					$saw_demo_prefix = true;
				}
			}
		}
		self::assertTrue( $saw_demo_prefix, 'Demo payments should carry the demo- prefix on provider_payment_id.' );
	}
}
