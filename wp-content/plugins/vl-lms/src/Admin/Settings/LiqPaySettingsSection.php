<?php

declare(strict_types=1);

namespace VL\LMS\Admin\Settings;

use VL\LMS\Payments\LiqPay\Settings as LiqPaySettings;

/**
 * Renders the LiqPay credentials block of the wp-admin Settings page.
 *
 * Three fields: public key (text), private key (password), sandbox flag
 * (checkbox). Each falls back to a "Визначено константою у wp-config.php"
 * lock row when the matching `VL_LMS_LIQPAY_*` constant is defined, so
 * hardened deploys keep working unchanged when the UI is added later.
 *
 * Below the inputs sits a small status panel — LiqPay callback URL and a
 * derived "Підключення активне"/"Налаштуйте обидва ключі" line. Mirrors
 * {@see ZoomSettingsSection}'s pattern; no live API ping happens here.
 *
 * Not declared `final` — unit tests subclass to override the
 * `is_constant_defined()` seam.
 *
 * @author Tymofii Synianskyi
 */
class LiqPaySettingsSection {

	public const string OPTION_PUBLIC_KEY  = 'vl_lms_liqpay_public_key';
	public const string OPTION_PRIVATE_KEY = 'vl_lms_liqpay_private_key';
	public const string OPTION_SANDBOX     = 'vl_lms_liqpay_sandbox';

	public const string CONST_PUBLIC_KEY  = 'VL_LMS_LIQPAY_PUBLIC_KEY';
	public const string CONST_PRIVATE_KEY = 'VL_LMS_LIQPAY_PRIVATE_KEY';
	public const string CONST_SANDBOX     = 'VL_LMS_LIQPAY_SANDBOX';

	public function __construct(
		private readonly LiqPaySettings $settings
	) {
	}

	/**
	 * Returns the field descriptors used by the page renderer and the
	 * form-handler write loop.
	 *
	 * @return list<array{label: string, option: string, constant: string, type: string}>
	 */
	public static function fields(): array {
		return [
			[
				'label'    => 'Public Key',
				'option'   => self::OPTION_PUBLIC_KEY,
				'constant' => self::CONST_PUBLIC_KEY,
				'type'     => 'text',
			],
			[
				'label'    => 'Private Key',
				'option'   => self::OPTION_PRIVATE_KEY,
				'constant' => self::CONST_PRIVATE_KEY,
				'type'     => 'password',
			],
			[
				'label'    => 'Sandbox',
				'option'   => self::OPTION_SANDBOX,
				'constant' => self::CONST_SANDBOX,
				'type'     => 'checkbox',
			],
		];
	}

	public function render(): void {
		echo '<h2>' . esc_html__( 'LiqPay — облікові дані', 'vl-lms' ) . '</h2>';
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
		} elseif ( 'checkbox' === $field['type'] ) {
			$value   = (string) get_option( $field['option'], '' );
			$checked = '1' === $value ? ' checked="checked"' : '';
			printf(
				'<label><input type="checkbox" id="%1$s" name="%1$s" value="1"%2$s /> %3$s</label>',
				esc_attr( $field['option'] ),
				$checked, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Hard-coded literal.
				esc_html__( 'Використовувати тестове середовище LiqPay', 'vl-lms' )
			);
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
		$callback_url = home_url( '/wp-json/vl/v1/payments/liqpay/callback' );

		echo '<h3>' . esc_html__( 'Стан', 'vl-lms' ) . '</h3>';

		echo '<p><strong>' . esc_html__( 'Адреса прийому колбеків LiqPay:', 'vl-lms' ) . '</strong> '
			. '<code id="vl-lms-liqpay-callback-url">' . esc_html( $callback_url ) . '</code> '
			. '<button type="button" class="button button-small" '
			. 'onclick="navigator.clipboard.writeText(document.getElementById(\'vl-lms-liqpay-callback-url\').textContent);">'
			. esc_html__( 'Скопіювати', 'vl-lms' ) . '</button></p>';

		if ( $this->settings->is_configured() ) {
			$mode = $this->settings->is_sandbox()
				? esc_html__( 'тестовий режим', 'vl-lms' )
				: esc_html__( 'бойовий режим', 'vl-lms' );
			echo '<p style="color:#2271b1"><strong>'
				. esc_html__( 'Підключення активне', 'vl-lms' )
				. '</strong> — ' . esc_html( $mode ) . '</p>';
		} else {
			echo '<p style="color:#b32d2e"><strong>'
				. esc_html__( 'Налаштуйте Public Key і Private Key', 'vl-lms' ) . '</strong></p>';
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
