<?php

declare(strict_types=1);

namespace VL\LMS\Admin\Settings;

use VL\LMS\Services\Zoom\Settings\ZoomSettingsProvider;

/**
 * Phase 9.5 — renders the Zoom credentials block of the wp-admin
 * Settings page.
 *
 * For each of the four credentials (`account_id`, `client_id`,
 * `client_secret`, `webhook_secret`) the section renders one of:
 *   - a "Визначено константою у wp-config.php" lock row (no input,
 *     no value reveal — wp-config may be world-readable on bad hosts);
 *   - a regular text/password input pre-filled from the matching
 *     `wp_options` row.
 *
 * Below the inputs sits a small status panel — webhook receiver URL
 * and a derived "Підключення активне"/"Налаштуйте всі чотири значення"
 * line. No live API ping happens in this phase.
 *
 * Not declared `final` — unit tests subclass to override the
 * `is_constant_defined()` seam.
 *
 * @author Tymofii Synianskyi
 */
class ZoomSettingsSection {

	public const string OPTION_ACCOUNT_ID     = 'vl_lms_zoom_account_id';
	public const string OPTION_CLIENT_ID      = 'vl_lms_zoom_client_id';
	public const string OPTION_CLIENT_SECRET  = 'vl_lms_zoom_client_secret';
	public const string OPTION_WEBHOOK_SECRET = 'vl_lms_zoom_webhook_secret';

	public const string CONST_ACCOUNT_ID     = 'VL_ZOOM_ACCOUNT_ID';
	public const string CONST_CLIENT_ID      = 'VL_ZOOM_CLIENT_ID';
	public const string CONST_CLIENT_SECRET  = 'VL_ZOOM_CLIENT_SECRET';
	public const string CONST_WEBHOOK_SECRET = 'VL_ZOOM_WEBHOOK_SECRET';

	public function __construct(
		private readonly ZoomSettingsProvider $provider
	) {
	}

	/**
	 * Returns the four credential descriptors used by the page renderer
	 * and the form-handler write loop. The wire shape matches what a
	 * `<table class="form-table">` row needs.
	 *
	 * @return list<array{label: string, option: string, constant: string, type: string}>
	 */
	public static function fields(): array {
		return [
			[
				'label'    => 'Account ID',
				'option'   => self::OPTION_ACCOUNT_ID,
				'constant' => self::CONST_ACCOUNT_ID,
				'type'     => 'text',
			],
			[
				'label'    => 'Client ID',
				'option'   => self::OPTION_CLIENT_ID,
				'constant' => self::CONST_CLIENT_ID,
				'type'     => 'text',
			],
			[
				'label'    => 'Client Secret',
				'option'   => self::OPTION_CLIENT_SECRET,
				'constant' => self::CONST_CLIENT_SECRET,
				'type'     => 'password',
			],
			[
				'label'    => 'Webhook Secret Token',
				'option'   => self::OPTION_WEBHOOK_SECRET,
				'constant' => self::CONST_WEBHOOK_SECRET,
				'type'     => 'password',
			],
		];
	}

	public function render(): void {
		echo '<h2>' . esc_html__( 'Zoom — облікові дані', 'vl-lms' ) . '</h2>';
		echo '<p class="description">' . esc_html__(
			'Якщо значення задане константою у wp-config.php, воно має пріоритет над цим полем.',
			'vl-lms'
		) . '</p>';

		echo '<table class="form-table" role="presentation"><tbody>';

		foreach ( self::fields() as $field ) {
			$this->render_row( $field );
		}

		echo '</tbody></table>';

		$this->render_status();
	}

	/**
	 * @param array{label: string, option: string, constant: string, type: string} $field
	 */
	private function render_row( array $field ): void {
		echo '<tr>';
		echo '<th scope="row"><label for="' . esc_attr( $field['option'] ) . '">'
			. esc_html( $field['label'] ) . '</label></th>';
		echo '<td>';

		if ( $this->is_constant_defined( $field['constant'] ) ) {
			echo '<span class="description"><em>'
				. esc_html__( 'Визначено константою у wp-config.php', 'vl-lms' )
				. '</em></span>';
		} else {
			$value = (string) get_option( $field['option'], '' );
			printf(
				'<input type="%s" id="%s" name="%s" value="%s" class="regular-text" autocomplete="off" />',
				esc_attr( $field['type'] ),
				esc_attr( $field['option'] ),
				esc_attr( $field['option'] ),
				esc_attr( $value )
			);
			// TODO: encrypt at rest in a future phase.
		}

		echo '</td>';
		echo '</tr>';
	}

	private function render_status(): void {
		$webhook_url = home_url( '/wp-json/vl/v1/webhooks/zoom' );

		echo '<h3>' . esc_html__( 'Стан', 'vl-lms' ) . '</h3>';

		echo '<p><strong>' . esc_html__( 'Адреса прийому вебхуків:', 'vl-lms' ) . '</strong> '
			. '<code id="vl-lms-zoom-webhook-url">' . esc_html( $webhook_url ) . '</code> '
			. '<button type="button" class="button button-small" '
			. 'onclick="navigator.clipboard.writeText(document.getElementById(\'vl-lms-zoom-webhook-url\').textContent);">'
			. esc_html__( 'Скопіювати', 'vl-lms' ) . '</button></p>';

		$credentials = $this->provider->get_credentials();
		if ( $credentials->is_configured() ) {
			echo '<p style="color:#2271b1"><strong>'
				. esc_html__( 'Підключення активне', 'vl-lms' ) . '</strong></p>';
		} else {
			echo '<p style="color:#b32d2e"><strong>'
				. esc_html__( 'Налаштуйте всі чотири значення', 'vl-lms' ) . '</strong></p>';
		}
	}

	/**
	 * Indirected so unit tests can subclass and toggle constant
	 * presence without touching the live PHP constant table.
	 */
	protected function is_constant_defined( string $name ): bool {
		return defined( $name );
	}
}
