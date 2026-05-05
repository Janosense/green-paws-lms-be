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
 * Phase 8.5 — order-paid email. Triggered by
 * {@see \VL\LMS\Services\Notifications\OrderPaidListener} on the
 * `vl_lms_order_paid` action that {@see \VL\LMS\Payments\LiqPay\CallbackHandler}
 * fires after a successful PAID transition. Purchaser-only.
 *
 * @author Tymofii Synianskyi
 */
class OrderPaidMailer {

	public function __construct(
		private readonly Logger $logger,
		private readonly AppUrlResolver $url_resolver,
		private readonly HtmlMailSender $sender
	) {
	}

	public function send( Order $order, Payment $payment ): bool {
		unset( $payment );

		$user = get_userdata( $order->user_id );
		if ( ! $user instanceof WP_User || '' === (string) $user->user_email ) {
			$this->logger->warning(
				'OrderPaidMailer: user not found or has no email.',
				[
					'user_id'    => $order->user_id,
					'order_uuid' => $order->uuid,
				]
			);
			return false;
		}

		$title = wp_strip_all_tags( $order->entity_title_snapshot );

		$subject = (string) apply_filters(
			'vl_lms_order_paid_subject',
			'Дякуємо за покупку — ' . $title,
			$order->uuid,
			$order->user_id
		);

		$body = (string) apply_filters(
			'vl_lms_order_paid_body',
			$this->default_body( $order, $user, $title ),
			$order->uuid,
			$order->user_id
		);

		return $this->sender->send( (string) $user->user_email, $subject, $body );
	}

	private function default_body( Order $order, WP_User $user, string $title ): string {
		$greeting_name         = '' !== (string) $user->first_name ? (string) $user->first_name : (string) $user->user_login;
		$entity_label          = PurchasableEntityType::COURSE === $order->entity_type ? 'курсу' : 'вебінару';
		$entity_label_genitive = PurchasableEntityType::COURSE === $order->entity_type ? 'Курс' : 'Вебінар';
		$cta_label             = PurchasableEntityType::COURSE === $order->entity_type ? 'Перейти до курсу' : 'Перейти до вебінару';

		$primary_url   = PurchasableEntityType::COURSE === $order->entity_type
			? $this->url_resolver->path( '/courses/' . $order->entity_slug )
			: $this->url_resolver->path( '/dashboard/webinars/' . $order->entity_slug );
		$secondary_url = $this->url_resolver->path( '/dashboard/orders/' . $order->uuid );

		$amount   = $order->amount->to_major_decimal();
		$currency = $order->amount->currency();
		$date     = wp_date( 'd.m.Y', $order->created_at->getTimestamp() );

		return sprintf(
			'<p>Доброго дня, %s!</p>'
			. '<p>Дякуємо за покупку. Замовлення підтверджено та оплачено.</p>'
			. '<p><strong>Деталі замовлення:</strong></p>'
			. '<ul>'
			. '<li>%s: %s</li>'
			. '<li>Сума: %s %s</li>'
			. '<li>Дата: %s</li>'
			. '</ul>'
			. '<p>Доступ відкрито. Перейдіть до %s, щоб розпочати:</p>'
			. '<p><a href="%s">%s</a></p>'
			. '<p>Якщо у вас виникли питання, ви завжди можете переглянути деталі замовлення:</p>'
			. '<p><a href="%s">Переглянути замовлення</a></p>'
			. '<p>— Команда Green Paws</p>',
			esc_html( $greeting_name ),
			esc_html( $entity_label_genitive ),
			esc_html( $title ),
			esc_html( $amount ),
			esc_html( $currency ),
			esc_html( $date ),
			esc_html( $entity_label ),
			esc_url( $primary_url ),
			esc_html( $cta_label ),
			esc_url( $secondary_url )
		);
	}
}
