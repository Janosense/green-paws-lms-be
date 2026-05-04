<?php

declare(strict_types=1);

namespace VL\LMS\Mail;

/**
 * Tiny `wp_mail` wrapper that scopes the `text/html` content-type filter
 * to a single send. Mirrors the inline pattern in
 * `Auth\Mail\VerificationMailer` so other plugins relying on `wp_mail` are
 * not affected by an accidentally-leaky filter.
 *
 * @author Tymofii Synianskyi
 */
class HtmlMailSender {

	public function send( string $to, string $subject, string $html_body ): bool {
		$content_type_callback = static fn (): string => 'text/html; charset=UTF-8';
		add_filter( 'wp_mail_content_type', $content_type_callback );
		try {
			return (bool) wp_mail( $to, $subject, $html_body );
		} finally {
			remove_filter( 'wp_mail_content_type', $content_type_callback );
		}
	}
}
