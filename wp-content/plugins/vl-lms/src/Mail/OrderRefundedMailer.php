<?php

declare(strict_types=1);

namespace VL\LMS\Mail;

use VL\LMS\Domain\Order\Order;
use VL\LMS\Domain\Order\PurchasableEntityType;
use VL\LMS\Domain\Payment\Payment;
use VL\LMS\Support\AppUrlResolver;
use VL\LMS\Support\Logger;
use WP_User;

/**
 * Phase 8.5 — order-refunded email. Triggered by
 * {@see \VL\LMS\Services\Notifications\OrderRefundedListener} on the
 * `vl_lms_order_refunded` action that {@see \VL\LMS\Refunds\RefundService}
 * fires after a successful refund. Purchaser-only.
 *
 * @author Tymofii Synianskyi
 */
class OrderRefundedMailer {

	public function __construct(
		private readonly Logger $logger,
		private readonly AppUrlResolver $url_resolver,
		private readonly HtmlMailSender $sender
	) {
	}

	public function send( Order $order, Payment $refund_payment ): bool {
		unset( $refund_payment );

		$user = get_userdata( $order->user_id );
		if ( ! $user instanceof WP_User || '' === (string) $user->user_email ) {
			$this->logger->warning(
				'OrderRefundedMailer: user not found or has no email.',
				[
					'user_id'    => $order->user_id,
					'order_uuid' => $order->uuid,
				]
			);
			return false;
		}

		$title = wp_strip_all_tags( $order->entity_title_snapshot );

		$subject = (string) apply_filters(
			'vl_lms_order_refunded_subject',
			'Кошти за замовлення повернено — ' . $title,
			$order->uuid,
			$order->user_id
		);

		$body = (string) apply_filters(
			'vl_lms_order_refunded_body',
			$this->default_body( $order, $user, $title ),
			$order->uuid,
			$order->user_id
		);

		return $this->sender->send( (string) $user->user_email, $subject, $body );
	}

	private function default_body( Order $order, WP_User $user, string $title ): string {
		$greeting_name   = '' !== (string) $user->first_name ? (string) $user->first_name : (string) $user->user_login;
		$entity_label    = PurchasableEntityType::COURSE === $order->entity_type ? 'Курс' : 'Вебінар';
		$entity_genitive = PurchasableEntityType::COURSE === $order->entity_type ? 'курсу' : 'вебінару';

		$dashboard_url = $this->url_resolver->path( '/dashboard/orders/' . $order->uuid );

		$amount      = $order->amount->to_major_decimal();
		$currency    = $order->amount->currency();
		$refunded_at = null === $order->refunded_at
			? wp_date( 'd.m.Y', ( new \DateTimeImmutable( 'now' ) )->getTimestamp() )
			: wp_date( 'd.m.Y', $order->refunded_at->getTimestamp() );

		return sprintf(
			'<p>Доброго дня, %s!</p>'
			. '<p>Кошти за ваше замовлення було повернено.</p>'
			. '<p><strong>Деталі:</strong></p>'
			. '<ul>'
			. '<li>%s: %s</li>'
			. '<li>Сума повернення: %s %s</li>'
			. '<li>Дата повернення: %s</li>'
			. '</ul>'
			. '<p>Зверніть увагу, що доступ до %s було скасовано.</p>'
			. '<p>Якщо у вас виникли питання, перегляньте деталі замовлення:</p>'
			. '<p><a href="%s">Переглянути замовлення</a></p>'
			. '<p>— Команда Green Paws</p>',
			esc_html( $greeting_name ),
			esc_html( $entity_label ),
			esc_html( $title ),
			esc_html( $amount ),
			esc_html( $currency ),
			esc_html( $refunded_at ),
			esc_html( $entity_genitive ),
			esc_url( $dashboard_url )
		);
	}
}
