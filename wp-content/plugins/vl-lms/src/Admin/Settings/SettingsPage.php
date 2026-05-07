<?php

declare(strict_types=1);

namespace VL\LMS\Admin\Settings;

/**
 * Phase 9.5 — wp-admin "Налаштування" page (`?page=vl-lms-settings`).
 *
 * Hosts {@see ZoomSettingsSection} and {@see LiqPaySettingsSection}; the
 * page slug + capability are surfaced as constants so
 * {@see \VL\LMS\Admin\Menu\AdminMenuProvider} and the `admin-post.php`
 * handler reference the same strings.
 *
 * Constant-precedence handling lives in each section: if `defined('VL_*')`
 * we never write that option, even when the form is POSTed. This matches
 * the wp-config.php precedence elsewhere so a hardened production deploy
 * keeps working unchanged when the UI is added later.
 *
 * Not declared `final` — unit tests subclass to bypass `wp_redirect`
 * and capability checks.
 *
 * @author Tymofii Synianskyi
 */
class SettingsPage {

	public const string PAGE_SLUG    = 'vl-lms-settings';
	public const string CAPABILITY   = 'manage_vl_lms_settings';
	public const string SAVE_ACTION  = 'vl_lms_save_settings';
	public const string NONCE_ACTION = 'vl_lms_save_settings';
	public const string NONCE_FIELD  = '_vl_lms_settings_nonce';
	public const string SAVED_QUERY  = 'saved';

	public function __construct(
		private readonly ZoomSettingsSection $zoom_section,
		private readonly LiqPaySettingsSection $liqpay_section
	) {
	}

	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Доступ заборонено.', 'vl-lms' ), '', [ 'response' => 403 ] );
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Налаштування Green Paws LMS', 'vl-lms' ) . '</h1>';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only success flag, no state mutated here.
		if ( isset( $_GET[ self::SAVED_QUERY ] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>'
				. esc_html__( 'Налаштування збережено.', 'vl-lms' )
				. '</p></div>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::SAVE_ACTION ) . '" />';
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

		$this->zoom_section->render();
		$this->liqpay_section->render();

		submit_button( __( 'Зберегти зміни', 'vl-lms' ) );

		echo '</form>';
		echo '</div>';
	}

	public function handle_save(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Доступ заборонено.', 'vl-lms' ), '', [ 'response' => 403 ] );
		}

		check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD );

		$fields = array_merge(
			ZoomSettingsSection::fields(),
			LiqPaySettingsSection::fields()
		);

		foreach ( $fields as $field ) {
			if ( $this->is_constant_defined( $field['constant'] ) ) {
				continue;
			}
			$option = $field['option'];

			if ( 'checkbox' === $field['type'] ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce checked above via check_admin_referer().
				$value = isset( $_POST[ $option ] ) ? '1' : '';
				update_option( $option, $value );
				continue;
			}

			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Sanitized below; nonce checked above via check_admin_referer().
			$raw = isset( $_POST[ $option ] ) ? wp_unslash( (string) $_POST[ $option ] ) : '';
			update_option( $option, sanitize_text_field( $raw ) );
		}

		$this->redirect_back();
	}

	/**
	 * Indirected so unit tests can subclass and toggle constant
	 * presence without touching the live PHP constant table.
	 */
	protected function is_constant_defined( string $name ): bool {
		return defined( $name );
	}

	/**
	 * Indirected so unit tests can subclass and bypass `wp_redirect` /
	 * `exit;` (which is fatal under PHPUnit).
	 */
	protected function redirect_back(): void {
		$url = add_query_arg(
			[
				'page'            => self::PAGE_SLUG,
				self::SAVED_QUERY => '1',
			],
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}
}
