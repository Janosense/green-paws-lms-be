<?php

declare(strict_types=1);

namespace VL\LMS\Admin\Orders;

use VL\LMS\Domain\Order\Order;
use VL\LMS\Domain\Order\OrderStatus;
use VL\LMS\Domain\Order\PurchasableEntityType;
use VL\LMS\Repositories\OrderRepository;

/**
 * Admin "Замовлення" list screen.
 *
 * Phase 8.6 — extends `\WP_List_Table` for the standard sort / search /
 * filter / paginate scaffolding. The page-controller in
 * {@see OrdersListPage} wraps this in the surrounding `<form>` and
 * search box.
 *
 * @author Tymofii Synianskyi
 */
class OrdersListTable extends \WP_List_Table {

	private const int PER_PAGE = 20;

	private const array SORT_WHITELIST = [ 'created_at', 'amount', 'status', 'user_email' ];

	/** @var array<int, array{email: string, display: string}> Per-request user lookup cache (avoids N+1). */
	private array $user_cache = [];

	public function __construct( private readonly OrderRepository $orders ) {
		parent::__construct(
			[
				'singular' => 'order',
				'plural'   => 'orders',
				'ajax'     => false,
			]
		);
	}

	/**
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return [
			'cb'                    => '<input type="checkbox" />',
			'created_at'            => 'Дата',
			'uuid'                  => 'UUID',
			'user_email'            => 'Покупець',
			'entity_title_snapshot' => 'Товар',
			'entity_type'           => 'Тип',
			'amount'                => 'Сума',
			'status'                => 'Статус',
		];
	}

	/**
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public function get_sortable_columns(): array {
		return [
			'created_at' => [ 'created_at', true ],
			'amount'     => [ 'amount', false ],
			'status'     => [ 'status', false ],
			'user_email' => [ 'user_email', false ],
		];
	}

	/**
	 * @return array<string, string>
	 */
	public function get_bulk_actions(): array {
		return [];
	}

	public function prepare_items(): void {
		$this->_column_headers = [
			$this->get_columns(),
			[],
			$this->get_sortable_columns(),
		];

		$status      = $this->request_string( 'status' );
		$entity_type = $this->request_string( 'entity_type' );
		$search      = $this->request_string( 's' );

		$sort_by = $this->request_string( 'orderby' ) ?? 'created_at';
		if ( ! in_array( $sort_by, self::SORT_WHITELIST, true ) ) {
			$sort_by = 'created_at';
		}
		$sort_dir_raw = $this->request_string( 'order' ) ?? 'DESC';
		$sort_dir     = 'asc' === strtolower( $sort_dir_raw ) ? 'ASC' : 'DESC';

		$page = (int) ( $this->request_string( 'paged' ) ?? '1' );
		$page = max( 1, $page );

		$result = $this->orders->list_for_admin(
			[
				'search'      => $search,
				'status'      => $this->normalize_status( $status ),
				'entity_type' => $this->normalize_entity_type( $entity_type ),
			],
			$page,
			self::PER_PAGE,
			$sort_by,
			$sort_dir
		);

		$this->items = $result['items'];

		$this->set_pagination_args(
			[
				'total_items' => $result['total'],
				'per_page'    => self::PER_PAGE,
				'total_pages' => max( 1, (int) ceil( $result['total'] / self::PER_PAGE ) ),
			]
		);
	}

	/**
	 * @param Order  $item
	 * @param string $column_name
	 *
	 * @return string
	 */
	public function column_default( $item, $column_name ): string {
		switch ( $column_name ) {
			case 'entity_title_snapshot':
				return esc_html( $item->entity_title_snapshot );
			default:
				return '';
		}
	}

	/**
	 * @param Order $item
	 */
	public function column_cb( $item ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		unset( $item );
		return '<input type="checkbox" disabled />';
	}

	public function column_created_at( Order $item ): string {
		$ts = $item->created_at->getTimestamp();
		return esc_html( wp_date( 'd.m.Y H:i', $ts ) );
	}

	public function column_uuid( Order $item ): string {
		$short = substr( $item->uuid, 0, 8 ) . '…';
		$href  = add_query_arg(
			[
				'page' => 'vl-lms-order-detail',
				'uuid' => $item->uuid,
			],
			admin_url( 'admin.php' )
		);
		return sprintf(
			'<a href="%s" title="%s"><code>%s</code></a>',
			esc_url( $href ),
			esc_attr( $item->uuid ),
			esc_html( $short )
		);
	}

	public function column_user_email( Order $item ): string {
		$lookup = $this->user_lookup( $item->user_id );
		if ( '' === $lookup['email'] ) {
			return '<em>(видалено)</em>';
		}
		return esc_html( $lookup['email'] );
	}

	public function column_entity_type( Order $item ): string {
		return PurchasableEntityType::COURSE === $item->entity_type ? 'Курс' : 'Вебінар';
	}

	public function column_amount( Order $item ): string {
		return esc_html( $item->amount->to_major_decimal() . ' ' . $item->amount->currency() );
	}

	public function column_status( Order $item ): string {
		$labels = [
			OrderStatus::PENDING->value          => 'Очікує оплати',
			OrderStatus::AWAITING_PAYMENT->value => 'Платіж у LiqPay',
			OrderStatus::PAID->value             => 'Оплачено',
			OrderStatus::FAILED->value           => 'Не пройшов',
			OrderStatus::CANCELLED->value        => 'Скасовано',
			OrderStatus::EXPIRED->value          => 'Прострочено',
			OrderStatus::REFUNDED->value         => 'Повернено',
		];
		$value  = $item->status->value;
		$label  = $labels[ $value ] ?? $value;
		return sprintf(
			'<span class="vl-status-badge vl-status-%s">%s</span>',
			esc_attr( $value ),
			esc_html( $label )
		);
	}

	/**
	 * @param string $which
	 */
	public function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}

		$current_status      = $this->request_string( 'status' ) ?? '';
		$current_entity_type = $this->request_string( 'entity_type' ) ?? '';

		$status_options = [
			''                                   => 'Усі статуси',
			OrderStatus::PENDING->value          => 'Очікує оплати',
			OrderStatus::AWAITING_PAYMENT->value => 'Платіж у LiqPay',
			OrderStatus::PAID->value             => 'Оплачено',
			OrderStatus::FAILED->value           => 'Не пройшов',
			OrderStatus::CANCELLED->value        => 'Скасовано',
			OrderStatus::EXPIRED->value          => 'Прострочено',
			OrderStatus::REFUNDED->value         => 'Повернено',
		];
		$entity_options = [
			''        => 'Усі типи',
			'course'  => 'Курси',
			'webinar' => 'Вебінари',
		];

		echo '<div class="alignleft actions">';
		echo '<select name="status">';
		foreach ( $status_options as $value => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( (string) $value ),
				selected( $current_status, $value, false ),
				esc_html( $label )
			);
		}
		echo '</select>';

		echo '<select name="entity_type">';
		foreach ( $entity_options as $value => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( (string) $value ),
				selected( $current_entity_type, $value, false ),
				esc_html( $label )
			);
		}
		echo '</select>';

		submit_button( 'Фільтрувати', '', 'filter_action', false );
		echo '</div>';
	}

	private function normalize_status( ?string $value ): ?string {
		if ( null === $value || '' === $value ) {
			return null;
		}
		foreach ( OrderStatus::cases() as $case ) {
			if ( $case->value === $value ) {
				return $value;
			}
		}
		return null;
	}

	private function normalize_entity_type( ?string $value ): ?string {
		if ( 'course' === $value || 'webinar' === $value ) {
			return $value;
		}
		return null;
	}

	private function request_string( string $key ): ?string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter inputs.
		if ( ! isset( $_REQUEST[ $key ] ) ) {
			return null;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter inputs.
		$raw = wp_unslash( $_REQUEST[ $key ] );
		if ( ! is_string( $raw ) ) {
			return null;
		}
		$value = sanitize_text_field( $raw );
		return '' === $value ? null : $value;
	}

	/**
	 * @return array{email: string, display: string}
	 */
	private function user_lookup( int $user_id ): array {
		if ( isset( $this->user_cache[ $user_id ] ) ) {
			return $this->user_cache[ $user_id ];
		}
		$user = get_userdata( $user_id );
		if ( ! $user instanceof \WP_User ) {
			$entry = [
				'email'   => '',
				'display' => '',
			];
		} else {
			$entry = [
				'email'   => (string) $user->user_email,
				'display' => (string) $user->display_name,
			];
		}
		$this->user_cache[ $user_id ] = $entry;
		return $entry;
	}
}
