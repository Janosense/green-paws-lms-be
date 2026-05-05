<?php

declare(strict_types=1);

namespace VL\LMS\Admin\Orders;

use VL\LMS\Domain\Order\Order;
use VL\LMS\Domain\Order\OrderStatus;
use VL\LMS\Domain\Order\PurchasableEntityType;
use VL\LMS\Domain\Payment\Payment;
use VL\LMS\Payments\Exception\PaymentProviderHttpException;
use VL\LMS\Payments\Exception\PaymentProviderRejectedException;
use VL\LMS\Payments\Exception\PaymentProviderUnavailableException;
use VL\LMS\Refunds\Exception\OrderNotFoundForRefundException;
use VL\LMS\Refunds\Exception\OrderNotRefundableException;
use VL\LMS\Refunds\RefundService;
use VL\LMS\Repositories\OrderRepository;
use VL\LMS\Repositories\PaymentRepository;

/**
 * Admin "Деталі замовлення" page.
 *
 * Phase 8.6 — custom-rendered (not list-table) detail screen with
 * summary block, lifecycle timeline, full `vl_payments` audit table
 * (with collapsible raw_payload rows) and a refund-form POST handler.
 *
 * The form-action wrapper catches every typed exception from
 * {@see RefundService::refund_order} and surfaces them as Ukrainian
 * admin notices.
 *
 * @author Tymofii Synianskyi
 */
class OrderDetailPage {

	private const string PAGE_SLUG = 'vl-lms-order-detail';

	/** @var list<array{type: string, message: string}> */
	private array $notices = [];

	public function __construct(
		private readonly OrderRepository $orders,
		private readonly PaymentRepository $payments,
		private readonly RefundService $refunds
	) {
	}

	public function render(): void {
		$uuid = $this->request_uuid();
		if ( null === $uuid ) {
			echo '<div class="wrap"><h1>Деталі замовлення</h1><div class="notice notice-error"><p>Невірний UUID замовлення.</p></div></div>';
			return;
		}

		// Process refund POST before reading the order so the latest state
		// is shown in the same render. Notices are queued for the layout.
		$this->maybe_handle_refund_post( $uuid );

		$order = $this->orders->find_by_uuid( $uuid );
		if ( null === $order ) {
			echo '<div class="wrap"><h1>Деталі замовлення</h1><div class="notice notice-error"><p>Замовлення не знайдено.</p></div></div>';
			return;
		}

		$payments = $this->payments->list_for_order( (int) $order->id );

		$this->render_layout( $order, $payments );
	}

	/**
	 * @param list<Payment> $payments
	 */
	private function render_layout( Order $order, array $payments ): void {
		echo '<div class="wrap">';
		printf(
			'<h1>Замовлення %s</h1>',
			esc_html( substr( $order->uuid, 0, 8 ) . '…' )
		);
		printf(
			'<a href="%s" class="page-title-action">← До списку</a>',
			esc_url( admin_url( 'admin.php?page=vl-lms-orders' ) )
		);

		$this->render_notices();

		echo '<div class="vl-admin-order-grid">';
		$this->render_header( $order );
		$this->render_summary( $order );
		$this->render_timeline( $order );
		$this->render_payments_audit( $payments );
		$this->render_actions( $order );
		echo '</div>';

		$this->render_payments_toggle_script();
		echo '</div>';
	}

	private function render_header( Order $order ): void {
		$entity_label = PurchasableEntityType::COURSE === $order->entity_type ? 'Курс' : 'Вебінар';
		echo '<section class="vl-admin-card">';
		printf(
			'<span class="vl-status-badge vl-status-%s">%s</span>',
			esc_attr( $order->status->value ),
			esc_html( $this->status_label( $order->status ) )
		);
		printf( '<h2>%s</h2>', esc_html( $order->entity_title_snapshot ) );
		printf(
			'<p class="description">%s: <a href="%s">Редагувати товар →</a></p>',
			esc_html( $entity_label ),
			esc_url( get_edit_post_link( $order->entity_id ) ?? '#' )
		);
		echo '</section>';
	}

	private function render_summary( Order $order ): void {
		$user = get_userdata( $order->user_id );

		$user_email = $user instanceof \WP_User ? (string) $user->user_email : '(видалено)';
		$user_name  = $user instanceof \WP_User ? (string) $user->display_name : '—';
		$user_link  = $user instanceof \WP_User
			? get_edit_user_link( (int) $user->ID )
			: '#';

		echo '<section class="vl-admin-card">';
		echo '<h3>Деталі</h3>';
		echo '<dl class="vl-admin-dl">';
		printf( '<dt>UUID</dt><dd><code>%s</code></dd>', esc_html( $order->uuid ) );
		printf(
			'<dt>Покупець</dt><dd>%s (%s) — <a href="%s">профіль</a></dd>',
			esc_html( $user_name ),
			esc_html( $user_email ),
			esc_url( $user_link )
		);
		printf(
			'<dt>Сума</dt><dd>%s %s</dd>',
			esc_html( $order->amount->to_major_decimal() ),
			esc_html( $order->amount->currency() )
		);
		printf( '<dt>Провайдер</dt><dd>%s</dd>', esc_html( $order->payment_provider ) );
		printf(
			'<dt>LiqPay Order ID</dt><dd><code>%s</code></dd>',
			esc_html( $order->liqpay_order_id ?? '—' )
		);
		echo '</dl>';
		echo '</section>';
	}

	private function render_timeline( Order $order ): void {
		echo '<section class="vl-admin-card">';
		echo '<h3>Хронологія</h3>';
		echo '<ul class="vl-timeline">';

		$events   = [];
		$events[] = [ $order->created_at, 'Замовлення створено' ];
		if ( null !== $order->paid_at ) {
			$events[] = [ $order->paid_at, 'Оплата отримана' ];
		}
		if ( null !== $order->cancelled_at ) {
			$events[] = [ $order->cancelled_at, 'Замовлення скасовано' ];
		}
		if ( null !== $order->refunded_at ) {
			$events[] = [ $order->refunded_at, 'Кошти повернено' ];
		}
		usort( $events, static fn ( array $a, array $b ): int => $a[0] <=> $b[0] );

		foreach ( $events as [ $when, $label ] ) {
			printf(
				'<li><time datetime="%s">%s</time> — %s</li>',
				esc_attr( $when->format( 'c' ) ),
				esc_html( wp_date( 'd.m.Y H:i', $when->getTimestamp() ) ),
				esc_html( $label )
			);
		}

		echo '</ul>';
		echo '</section>';
	}

	/**
	 * @param list<Payment> $payments
	 */
	private function render_payments_audit( array $payments ): void {
		echo '<section class="vl-admin-card">';
		printf(
			'<h3>Платіжний журнал (%d записів)</h3>',
			count( $payments )
		);

		if ( [] === $payments ) {
			echo '<p class="description">Записів немає.</p></section>';
			return;
		}

		echo '<table class="widefat">';
		echo '<thead><tr>';
		echo '<th>Дата</th><th>Тип</th><th>Статус LiqPay</th><th>Сума</th><th>Idempotency</th><th></th>';
		echo '</tr></thead>';
		echo '<tbody>';

		foreach ( $payments as $payment ) {
			$row_id = sprintf( 'vl-payment-detail-%d', (int) $payment->id );
			printf(
				'<tr data-payment-id="%d" class="vl-payment-row"><td>%s</td><td>%s</td><td>%s</td><td>%s %s</td><td><code>%s</code></td><td><a href="#" class="vl-payment-toggle" data-target="%s">показати ↓</a></td></tr>',
				(int) $payment->id,
				esc_html( wp_date( 'd.m.Y H:i', $payment->received_at->getTimestamp() ) ),
				esc_html( $payment->transaction_type->value ),
				esc_html( $payment->raw_provider_status ),
				esc_html( $payment->amount->to_major_decimal() ),
				esc_html( $payment->amount->currency() ),
				esc_html( $payment->idempotency_key ),
				esc_attr( $row_id )
			);
			printf(
				'<tr id="%s" class="vl-payment-detail" style="display:none;"><td colspan="6"><strong>provider_payment_id:</strong> <code>%s</code><br><strong>provider_action:</strong> <code>%s</code><br><strong>raw_payload:</strong><pre>%s</pre></td></tr>',
				esc_attr( $row_id ),
				esc_html( $payment->provider_payment_id ?? '—' ),
				esc_html( $payment->provider_action ),
				esc_html( $payment->raw_payload )
			);
		}

		echo '</tbody></table>';
		echo '</section>';
	}

	private function render_actions( Order $order ): void {
		if ( OrderStatus::PAID !== $order->status ) {
			echo '<section class="vl-admin-card">';
			printf(
				'<p class="description">Замовлення в статусі «%s» не може бути відшкодоване.</p>',
				esc_html( $this->status_label( $order->status ) )
			);
			echo '</section>';
			return;
		}

		if ( ! current_user_can( 'vl_refund_orders' ) ) {
			return;
		}

		$nonce_action = 'vl_refund_order_' . $order->uuid;
		$action_url   = add_query_arg(
			[
				'page'   => self::PAGE_SLUG,
				'action' => 'refund',
				'uuid'   => $order->uuid,
			],
			admin_url( 'admin.php' )
		);
		$confirm      = sprintf(
			'Ви впевнені? Це відшкодує %s %s і скасує доступ покупця. Дію не можна скасувати.',
			$order->amount->to_major_decimal(),
			$order->amount->currency()
		);

		echo '<section class="vl-admin-card vl-admin-actions">';
		echo '<h3>Дії</h3>';
		printf(
			'<form method="post" action="%s" id="vl-refund-form">',
			esc_url( $action_url )
		);
		wp_nonce_field( $nonce_action );
		$confirm_json = wp_json_encode( $confirm );
		if ( ! is_string( $confirm_json ) ) {
			$confirm_json = '"Підтвердити?"';
		}
		printf(
			'<button type="submit" class="button button-primary" onclick="return confirm(%s);">Відшкодувати %s %s</button>',
			esc_attr( $confirm_json ),
			esc_html( $order->amount->to_major_decimal() ),
			esc_html( $order->amount->currency() )
		);
		echo '</form>';
		echo '</section>';
	}

	private function render_payments_toggle_script(): void {
		echo '<script>
		document.addEventListener("click", function (e) {
			var t = e.target.closest && e.target.closest(".vl-payment-toggle");
			if (!t) return;
			e.preventDefault();
			var id = t.getAttribute("data-target");
			var row = document.getElementById(id);
			if (!row) return;
			row.style.display = row.style.display === "none" ? "table-row" : "none";
		});
		</script>';
	}

	private function maybe_handle_refund_post( string $uuid ): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified below.
		$action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['action'] ) ) : '';
		if ( 'refund' !== $action ) {
			return;
		}

		$nonce_action = 'vl_refund_order_' . $uuid;
		check_admin_referer( $nonce_action );

		if ( ! current_user_can( 'vl_refund_orders' ) ) {
			wp_die( esc_html__( 'Недостатньо прав.', 'vl-lms' ), '', [ 'response' => 403 ] );
		}

		try {
			$this->refunds->refund_order( $uuid );
			$this->push_notice( 'success', 'Замовлення повернено. Доступ скасовано, лист надіслано.' );
		} catch ( OrderNotFoundForRefundException ) {
			$this->push_notice( 'error', 'Замовлення не знайдено.' );
		} catch ( OrderNotRefundableException $ex ) {
			$this->push_notice(
				'error',
				sprintf(
					'Замовлення в статусі «%s» не може бути відшкодоване.',
					$this->status_label( $ex->current_status() )
				)
			);
		} catch ( PaymentProviderUnavailableException ) {
			$this->push_notice( 'error', 'LiqPay не налаштовано. Перевірте константи `VL_LMS_LIQPAY_*`.' );
		} catch ( PaymentProviderHttpException $ex ) {
			$this->push_notice(
				'error',
				sprintf( 'Помилка зв\'язку з LiqPay: %s. Спробуйте ще раз або перевірте логи.', $ex->getMessage() )
			);
		} catch ( PaymentProviderRejectedException $ex ) {
			$this->push_notice(
				'error',
				sprintf(
					'LiqPay відхилив запит на повернення: %s. %s',
					$ex->provider_err_code() ?? '—',
					$ex->getMessage()
				)
			);
		}
	}

	private function render_notices(): void {
		foreach ( $this->notices as $notice ) {
			printf(
				'<div class="notice notice-%s"><p>%s</p></div>',
				esc_attr( $notice['type'] ),
				esc_html( $notice['message'] )
			);
		}
	}

	private function push_notice( string $type, string $message ): void {
		$this->notices[] = [
			'type'    => $type,
			'message' => $message,
		];
	}

	private function status_label( OrderStatus $status ): string {
		return match ( $status ) {
			OrderStatus::PENDING           => 'Очікує оплати',
			OrderStatus::AWAITING_PAYMENT  => 'Платіж у LiqPay',
			OrderStatus::PAID              => 'Оплачено',
			OrderStatus::FAILED            => 'Не пройшов',
			OrderStatus::CANCELLED         => 'Скасовано',
			OrderStatus::EXPIRED           => 'Прострочено',
			OrderStatus::REFUNDED          => 'Повернено',
		};
	}

	private function request_uuid(): ?string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only param sanitised below.
		if ( ! isset( $_GET['uuid'] ) ) {
			return null;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Sanitised via sanitize_text_field.
		$raw = wp_unslash( (string) $_GET['uuid'] );
		$raw = sanitize_text_field( $raw );
		if ( ! preg_match( '/^[0-9a-f-]{36}$/i', $raw ) ) {
			return null;
		}
		return $raw;
	}
}
