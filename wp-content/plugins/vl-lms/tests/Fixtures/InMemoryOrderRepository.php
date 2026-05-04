<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Fixtures;

use VL\LMS\Domain\Order\Order;
use VL\LMS\Domain\Order\OrderStatus;
use VL\LMS\Domain\Order\PurchasableEntityType;
use VL\LMS\Repositories\OrderRepository;

/**
 * In-memory double of {@see OrderRepository} for service-level tests.
 *
 * Extends the production repository but overrides every public method so
 * no `$wpdb` call ever happens. Rows live in a simple associative array
 * keyed by primary id.
 */
final class InMemoryOrderRepository extends OrderRepository {

	private const string DATETIME_FORMAT = 'Y-m-d H:i:s';

	/** @var array<int, array<string, mixed>> */
	private array $rows = [];

	private int $next_id = 1;

	public function insert( Order $order ): int {
		if ( null !== $order->id ) {
			throw new \DomainException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception.
				sprintf( 'Cannot insert order %d — it already has an id.', $order->id )
			);
		}

		$id                = $this->next_id++;
		$row               = $order->to_row();
		$row['id']         = $id;
		$this->rows[ $id ] = $row;

		return $id;
	}

	public function update( Order $order ): bool {
		if ( null === $order->id ) {
			throw new \DomainException( 'Cannot update an unsaved order — id is null.' );
		}
		if ( ! isset( $this->rows[ $order->id ] ) ) {
			return false;
		}

		$row                      = $order->to_row();
		$row['id']                = $order->id;
		$this->rows[ $order->id ] = $row;

		return true;
	}

	public function update_provider_reference( int $order_id, string $reference ): bool {
		if ( ! isset( $this->rows[ $order_id ] ) ) {
			return false;
		}
		$this->rows[ $order_id ]['liqpay_order_id'] = $reference;
		return true;
	}

	public function update_status(
		int $order_id,
		OrderStatus $new_status,
		?\DateTimeImmutable $timestamp = null
	): bool {
		if ( ! isset( $this->rows[ $order_id ] ) ) {
			return false;
		}

		$this->rows[ $order_id ]['status'] = $new_status->value;

		$timestamp_column = match ( $new_status ) {
			OrderStatus::PAID      => 'paid_at',
			OrderStatus::CANCELLED => 'cancelled_at',
			OrderStatus::REFUNDED  => 'refunded_at',
			default                => null,
		};

		if ( null !== $timestamp_column ) {
			$value                                        = ( $timestamp ?? new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) )->format( self::DATETIME_FORMAT );
			$this->rows[ $order_id ][ $timestamp_column ] = $value;
		}

		return true;
	}

	public function find_by_id( int $id ): ?Order {
		if ( ! isset( $this->rows[ $id ] ) ) {
			return null;
		}
		return Order::from_row( $this->rows[ $id ] );
	}

	public function find_by_uuid( string $uuid ): ?Order {
		foreach ( $this->rows as $row ) {
			if ( (string) $row['uuid'] === $uuid ) {
				return Order::from_row( $row );
			}
		}
		return null;
	}

	public function find_by_provider_reference( string $provider, string $reference ): ?Order {
		foreach ( $this->rows as $row ) {
			if ( (string) $row['payment_provider'] === $provider
				&& null !== ( $row['liqpay_order_id'] ?? null )
				&& (string) $row['liqpay_order_id'] === $reference
			) {
				return Order::from_row( $row );
			}
		}
		return null;
	}

	/**
	 * @param list<OrderStatus>|null $statuses
	 *
	 * @return array{items: list<Order>, total: int}
	 */
	public function list_for_user(
		int $user_id,
		?array $statuses,
		int $page,
		int $per_page
	): array {
		$page     = max( 1, $page );
		$per_page = max( 1, $per_page );

		if ( null !== $statuses && [] === $statuses ) {
			return [
				'items' => [],
				'total' => 0,
			];
		}

		$matched = [];
		foreach ( $this->rows as $row ) {
			if ( (int) $row['user_id'] !== $user_id ) {
				continue;
			}
			if ( null !== $statuses ) {
				$status_values = array_map( static fn ( OrderStatus $s ): string => $s->value, $statuses );
				if ( ! in_array( (string) $row['status'], $status_values, true ) ) {
					continue;
				}
			}
			$matched[] = $row;
		}

		usort(
			$matched,
			static fn ( array $a, array $b ): int =>
				( (string) $b['created_at'] ) <=> ( (string) $a['created_at'] )
		);

		$total  = count( $matched );
		$offset = ( $page - 1 ) * $per_page;
		$slice  = array_slice( $matched, $offset, $per_page );

		$items = [];
		foreach ( $slice as $row ) {
			$items[] = Order::from_row( $row );
		}

		return [
			'items' => $items,
			'total' => $total,
		];
	}

	public function find_open_for_user_and_entity(
		int $user_id,
		PurchasableEntityType $entity_type,
		int $entity_id
	): ?Order {
		$matched = [];
		foreach ( $this->rows as $row ) {
			if ( (int) $row['user_id'] !== $user_id ) {
				continue;
			}
			if ( (string) $row['entity_type'] !== $entity_type->value ) {
				continue;
			}
			if ( (int) $row['entity_id'] !== $entity_id ) {
				continue;
			}
			$status = (string) $row['status'];
			if ( OrderStatus::PENDING->value !== $status && OrderStatus::AWAITING_PAYMENT->value !== $status ) {
				continue;
			}
			$matched[] = $row;
		}

		if ( [] === $matched ) {
			return null;
		}

		usort(
			$matched,
			static fn ( array $a, array $b ): int =>
				( (string) $b['created_at'] ) <=> ( (string) $a['created_at'] )
		);

		return Order::from_row( $matched[0] );
	}

	/**
	 * @return list<Order>
	 */
	public function list_expired_open( \DateTimeImmutable $now, int $limit = 100 ): array {
		$cutoff = $now->format( self::DATETIME_FORMAT );

		$matched = [];
		foreach ( $this->rows as $row ) {
			$status = (string) $row['status'];
			if ( OrderStatus::PENDING->value !== $status && OrderStatus::AWAITING_PAYMENT->value !== $status ) {
				continue;
			}
			if ( ( (string) $row['expires_at'] ) > $cutoff ) {
				continue;
			}
			$matched[] = $row;
		}

		usort(
			$matched,
			static fn ( array $a, array $b ): int =>
				( (string) $a['expires_at'] ) <=> ( (string) $b['expires_at'] )
		);

		$slice = array_slice( $matched, 0, max( 1, $limit ) );

		$out = [];
		foreach ( $slice as $row ) {
			$out[] = Order::from_row( $row );
		}
		return $out;
	}

	/**
	 * Test helper: directly seed a row.
	 *
	 * @param array<string, mixed> $overrides
	 */
	public function seed( array $overrides = [] ): int {
		$id = $this->next_id++;

		$defaults = [
			'id'                    => $id,
			'uuid'                  => sprintf( '00000000-0000-4000-8000-%012d', $id ),
			'user_id'               => 1,
			'status'                => OrderStatus::PENDING->value,
			'payment_provider'      => 'liqpay',
			'liqpay_order_id'       => null,
			'entity_type'           => PurchasableEntityType::COURSE->value,
			'entity_id'             => 100,
			'entity_slug'           => 'sample-course',
			'entity_title_snapshot' => 'Sample Course',
			'amount'                => '1500.00',
			'currency'              => 'UAH',
			'created_at'            => '2026-05-01 12:00:00',
			'expires_at'            => '2026-05-02 12:00:00',
			'paid_at'               => null,
			'cancelled_at'          => null,
			'refunded_at'           => null,
			'metadata'              => null,
		];

		$this->rows[ $id ] = array_merge( $defaults, $overrides, [ 'id' => $id ] );

		return $id;
	}
}
