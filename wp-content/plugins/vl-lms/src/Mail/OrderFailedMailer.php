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
 * Phase 8.5 — order-failed email. Triggered by
 * {@see \VL\LMS\Services\Notifications\OrderFailedListener} on the
 * `vl_lms_order_failed` action that {@see \VL\LMS\Payments\LiqPay\CallbackHandler}
 * fires after a successful FAILED transition. Purchaser-only.
 *
 * @author Tymofii Synianskyi
 */
class OrderFailedMailer {

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
				'OrderFailedMailer: user not found or has no email.',
				[
					'user_id'    => $order->user_id,
					'order_uuid' => $order->uuid,
				]
			);
			return false;
		}

		$title = wp_strip_all_tags( $order->entity_title_snapshot );

		$subject = (string) apply_filters(
			'vl_lms_order_failed_subject',
			'Платіж не пройшов — ' . $title,
			$order->uuid,
			$order->user_id
		);

		$body = (string) apply_filters(
			'vl_lms_order_failed_body',
			$this->default_body( $order, $user, $title ),
			$order->uuid,
			$order->user_id
		);

		return $this->sender->send( (string) $user->user_email, $subject, $body );
	}

	private function default_body( Order $order, WP_User $user, string $title ): string {
		$greeting_name = '' !== (string) $user->first_name ? (string) $user->first_name : (string) $user->user_login;
		$entity_label  = PurchasableEntityType::COURSE === $order->entity_type ? 'Курс' : 'Вебінар';

		$retry_url     = $this->url_resolver->path(
			'/checkout/' . $order->entity_slug . '?type=' . $order->entity_type->value
		);
		$dashboard_url = $this->url_resolver->path( '/dashboard/orders/' . $order->uuid );

		$amount   = $order->amount->to_major_decimal();
		$currency = $order->amount->currency();

		return sprintf(
			'<p>Доброго дня, %s!</p>'
			. '<p>Спроба оплати замовлення не була успішною.</p>'
			. '<p><strong>Деталі:</strong></p>'
			. '<ul>'
			. '<li>%s: %s</li>'
			. '<li>Сума: %s %s</li>'
			. '</ul>'
			. '<p>Ви можете спробувати оплатити ще раз або переглянути замовлення:</p>'
			. '<p><a href="%s">Спробувати ще раз</a></p>'
			. '<p><a href="%s">Переглянути замовлення</a></p>'
			. '<p>Якщо проблема повторюється, зверніться до своєї карткової установи.</p>'
			. '<p>— Команда Green Paws</p>',
			esc_html( $greeting_name ),
			esc_html( $entity_label ),
			esc_html( $title ),
			esc_html( $amount ),
			esc_html( $currency ),
			esc_url( $retry_url ),
			esc_url( $dashboard_url )
		);
	}
}
