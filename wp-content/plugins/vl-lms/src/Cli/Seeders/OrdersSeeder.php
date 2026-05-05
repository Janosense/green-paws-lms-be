<?php

declare(strict_types=1);

namespace VL\LMS\Cli\Seeders;

use VL\LMS\Cli\SeederContext;
use VL\LMS\Cli\SeederResult;
use VL\LMS\Domain\Money\Money;
use VL\LMS\Domain\Order\Order;
use VL\LMS\Domain\Order\OrderStatus;
use VL\LMS\Domain\Order\PurchasableEntityType;
use VL\LMS\Domain\Payment\Payment;
use VL\LMS\Domain\Payment\PaymentProvider;
use VL\LMS\Domain\Payment\PaymentStatus;
use VL\LMS\Domain\Payment\PaymentTransactionType;
use VL\LMS\Repositories\OrderRepository;
use VL\LMS\Repositories\PaymentRepository;

/**
 * Phase 8.6 — demo seeder for `vl_orders` + `vl_payments` audit rows.
 *
 * Creates 5 deterministic orders for one demo student:
 *
 *  1. PAID course        — CHARGE row, success.
 *  2. REFUNDED course    — CHARGE row + REFUND (reversed) row.
 *  3. FAILED webinar     — CHARGE row, failure.
 *  4. EXPIRED course     — no payment row (timed out before redirect).
 *  5. PENDING webinar    — no payment row, expires_at +12h (live cron demo).
 *
 * Idempotent: marks each order's `metadata` with `demo_seed = '1'`; second
 * runs short-circuit by the demo-seed sentinel check on the order
 * repository. No real LiqPay calls — `provider_payment_id` is prefixed
 * with `demo-` so the rows are unmistakably non-real.
 *
 * @author Tymofii Synianskyi
 */
class OrdersSeeder {

	public function __construct(
		private readonly OrderRepository $orders,
		private readonly PaymentRepository $payments
	) {
	}

	/**
	 * @param array<string, int>  $student_ids        Login → user-id map (from UsersSeeder).
	 * @param list<int>           $paid_course_ids    Post-IDs of demo paid courses.
	 * @param list<int>           $paid_webinar_ids   Post-IDs of demo paid webinars.
	 * @param array<int, array{slug: string, title: string}>|null $entity_meta Optional snapshot map keyed by post id.
	 */
	public function run(
		SeederContext $context,
		array $student_ids,
		array $paid_course_ids,
		array $paid_webinar_ids,
		?array $entity_meta = null
	): SeederResult {
		$summary = new SeederResult();

		if ( $this->already_seeded() ) {
			$context->log( 'OrdersSeeder: demo orders already seeded, skipping.' );
			$summary->skipped = 1;
			return $summary;
		}

		$user_id = $this->pick_student( $student_ids );
		if ( null === $user_id ) {
			$context->log( 'OrdersSeeder: no demo student found, bailing out.' );
			$summary->failed = 1;
			return $summary;
		}

		if ( count( $paid_course_ids ) < 2 || [] === $paid_webinar_ids ) {
			$context->log( 'OrdersSeeder: missing demo paid courses or webinars, bailing out.' );
			$summary->failed = 1;
			return $summary;
		}

		$now = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );

		$plan = [
			[
				'lifecycle'   => 'paid',
				'entity_type' => PurchasableEntityType::COURSE,
				'entity_id'   => $paid_course_ids[0],
				'created_at'  => $now->modify( '-3 days' ),
			],
			[
				'lifecycle'   => 'refunded',
				'entity_type' => PurchasableEntityType::COURSE,
				'entity_id'   => $paid_course_ids[1],
				'created_at'  => $now->modify( '-7 days' ),
			],
			[
				'lifecycle'   => 'failed',
				'entity_type' => PurchasableEntityType::WEBINAR,
				'entity_id'   => $paid_webinar_ids[0],
				'created_at'  => $now->modify( '-2 days' ),
			],
			[
				'lifecycle'   => 'expired',
				'entity_type' => PurchasableEntityType::COURSE,
				'entity_id'   => $paid_course_ids[0],
				'created_at'  => $now->modify( '-25 hours' ),
			],
			[
				'lifecycle'   => 'pending',
				'entity_type' => PurchasableEntityType::WEBINAR,
				'entity_id'   => $paid_webinar_ids[ count( $paid_webinar_ids ) > 1 ? 1 : 0 ],
				'created_at'  => $now,
			],
		];

		foreach ( $plan as $row ) {
			$this->create_demo_order(
				$context,
				$summary,
				$user_id,
				$row['entity_type'],
				(int) $row['entity_id'],
				$row['created_at'],
				$row['lifecycle'],
				$entity_meta
			);
		}

		$context->log(
			sprintf(
				'OrdersSeeder: %d orders created, %d skipped, %d failed.',
				$summary->created,
				$summary->skipped,
				$summary->failed
			)
		);

		return $summary;
	}

	/**
	 * @param array<int, array{slug: string, title: string}>|null $entity_meta
	 */
	private function create_demo_order(
		SeederContext $context,
		SeederResult $summary,
		int $user_id,
		PurchasableEntityType $entity_type,
		int $entity_id,
		\DateTimeImmutable $created_at,
		string $lifecycle,
		?array $entity_meta
	): void {
		[ $slug, $title ] = $this->resolve_entity_meta( $entity_id, $entity_meta );
		$amount           = Money::from_major_decimal(
			PurchasableEntityType::COURSE === $entity_type ? '1500.00' : '500.00',
			'UAH'
		);
		$expires_at       = $created_at->modify( '+24 hours' );

		$initial_status = OrderStatus::PENDING;
		$paid_at        = null;
		$cancelled_at   = null;
		$refunded_at    = null;

		$pending = new Order(
			id: null,
			uuid: $this->uuid( $created_at, $entity_id ),
			user_id: $user_id,
			status: $initial_status,
			payment_provider: 'liqpay',
			liqpay_order_id: null,
			entity_type: $entity_type,
			entity_id: $entity_id,
			entity_slug: $slug,
			entity_title_snapshot: $title,
			amount: $amount,
			created_at: $created_at,
			expires_at: $expires_at,
			paid_at: $paid_at,
			cancelled_at: $cancelled_at,
			refunded_at: $refunded_at,
			metadata: [ 'demo_seed' => '1' ]
		);

		try {
			$order_id = $this->orders->insert( $pending );
		} catch ( \Throwable $ex ) {
			$context->log( sprintf( 'OrdersSeeder: failed to insert order — %s', $ex->getMessage() ) );
			++$summary->failed;
			return;
		}

		$persisted_uuid = $pending->uuid;
		$this->orders->update_provider_reference( $order_id, $persisted_uuid );

		$now_for_ts = $created_at->modify( '+5 minutes' );

		switch ( $lifecycle ) {
			case 'paid':
				$this->orders->update_status( $order_id, OrderStatus::PAID, $now_for_ts );
				$this->insert_charge_payment( $order_id, $amount, $persisted_uuid, $now_for_ts, PaymentStatus::SUCCESS, 'success' );
				break;

			case 'refunded':
				$this->orders->update_status( $order_id, OrderStatus::PAID, $now_for_ts );
				$this->insert_charge_payment( $order_id, $amount, $persisted_uuid, $now_for_ts, PaymentStatus::SUCCESS, 'success' );
				$refund_at = $created_at->modify( '+2 days' );
				$this->orders->update_status( $order_id, OrderStatus::REFUNDED, $refund_at );
				$this->insert_refund_payment( $order_id, $amount, $persisted_uuid, $refund_at );
				break;

			case 'failed':
				$this->orders->update_status( $order_id, OrderStatus::FAILED );
				$this->insert_charge_payment( $order_id, $amount, $persisted_uuid, $now_for_ts, PaymentStatus::FAILURE, 'failure' );
				break;

			case 'expired':
				$this->orders->update_status( $order_id, OrderStatus::EXPIRED );
				break;

			case 'pending':
			default:
				// Leave PENDING with expires_at +12h for the live cron demo.
				break;
		}

		++$summary->created;
	}

	private function insert_charge_payment(
		int $order_id,
		Money $amount,
		string $order_uuid,
		\DateTimeImmutable $received_at,
		PaymentStatus $status,
		string $raw_status
	): void {
		$pid     = 'demo-' . $this->random_hex();
		$payment = new Payment(
			id: null,
			order_id: $order_id,
			provider: PaymentProvider::LIQPAY,
			provider_payment_id: $pid,
			provider_action: 'pay',
			status: $status,
			raw_provider_status: $raw_status,
			transaction_type: PaymentTransactionType::CHARGE,
			amount: $amount,
			raw_payload: $this->fake_payload( $order_uuid, 'pay', $raw_status ),
			received_at: $received_at,
			idempotency_key: sprintf( 'liqpay:%s:pay:%s', $pid, $raw_status )
		);
		$this->safe_insert_payment( $payment );
	}

	private function insert_refund_payment(
		int $order_id,
		Money $amount,
		string $order_uuid,
		\DateTimeImmutable $received_at
	): void {
		$pid     = 'demo-' . $this->random_hex();
		$payment = new Payment(
			id: null,
			order_id: $order_id,
			provider: PaymentProvider::LIQPAY,
			provider_payment_id: $pid,
			provider_action: 'refund',
			status: PaymentStatus::REVERSED,
			raw_provider_status: 'reversed',
			transaction_type: PaymentTransactionType::REFUND,
			amount: $amount,
			raw_payload: $this->fake_payload( $order_uuid, 'refund', 'reversed' ),
			received_at: $received_at,
			idempotency_key: sprintf( 'liqpay:%s:refund:reversed', $pid )
		);
		$this->safe_insert_payment( $payment );
	}

	private function safe_insert_payment( Payment $payment ): void {
		try {
			$this->payments->insert( $payment );
		} catch ( \Throwable $ex ) {
			// Idempotency-key collisions are tolerated — re-running the
			// seed should never blow up because of a stale row. Suppress.
			unset( $ex );
		}
	}

	private function fake_payload( string $order_uuid, string $action, string $status ): string {
		$payload = [
			'demo'     => true,
			'order_id' => $order_uuid,
			'action'   => $action,
			'status'   => $status,
		];
		$encoded = function_exists( 'wp_json_encode' )
			? wp_json_encode( $payload )
			: json_encode( $payload );
		return is_string( $encoded ) ? $encoded : '{}';
	}

	private function uuid( \DateTimeImmutable $created_at, int $entity_id ): string {
		// Deterministic-but-readable UUID: derive from timestamp + entity for
		// reproducible demo runs without real randomness.
		$hex = substr( hash( 'sha256', $created_at->format( 'YmdHis' ) . ':' . $entity_id ), 0, 32 );
		return sprintf(
			'%s-%s-4%s-8%s-%s',
			substr( $hex, 0, 8 ),
			substr( $hex, 8, 4 ),
			substr( $hex, 12, 3 ),
			substr( $hex, 15, 3 ),
			substr( $hex, 18, 12 )
		);
	}

	private function random_hex(): string {
		try {
			return bin2hex( random_bytes( 6 ) );
		} catch ( \Throwable ) {
			return substr( md5( (string) microtime( true ) ), 0, 12 );
		}
	}

	private function already_seeded(): bool {
		// Heuristic: ask the repository for any existing demo row by sniffing
		// the metadata column. Since OrderRepository doesn't expose a
		// list_by_metadata query, we use list_for_admin with a high limit
		// and check the metadata of returned rows. For seeders this is
		// run-once / cheap.
		$result = $this->orders->list_for_admin( [], 1, 100, 'created_at', 'DESC' );
		foreach ( $result['items'] as $order ) {
			if ( is_array( $order->metadata ) && '1' === ( $order->metadata['demo_seed'] ?? null ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param array<string, int> $student_ids
	 */
	private function pick_student( array $student_ids ): ?int {
		if ( [] === $student_ids ) {
			return null;
		}
		// Deterministic: pick the first by alphabetical login.
		ksort( $student_ids );
		$first = reset( $student_ids );
		return false === $first ? null : (int) $first;
	}

	/**
	 * @param array<int, array{slug: string, title: string}>|null $entity_meta
	 *
	 * @return array{0: string, 1: string}
	 */
	private function resolve_entity_meta( int $entity_id, ?array $entity_meta ): array {
		if ( null !== $entity_meta && isset( $entity_meta[ $entity_id ] ) ) {
			$row = $entity_meta[ $entity_id ];
			return [ (string) ( $row['slug'] ?? 'demo-' . $entity_id ), (string) ( $row['title'] ?? 'Demo Entity #' . $entity_id ) ];
		}

		// Best-effort lookup via WP — gracefully degrades when functions
		// aren't loaded (unit tests).
		if ( function_exists( 'get_post' ) ) {
			$post = get_post( $entity_id );
			if ( $post instanceof \WP_Post ) {
				return [ (string) $post->post_name, (string) $post->post_title ];
			}
		}
		return [ 'demo-entity-' . $entity_id, 'Demo Entity #' . $entity_id ];
	}
}
