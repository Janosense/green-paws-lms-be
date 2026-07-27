<?php

declare(strict_types=1);

namespace VL\LMS\Admin\MetaBoxes;

use WP_Post;

/**
 * Base class for VL LMS CPT meta-boxes.
 *
 * Concrete subclasses describe one CPT screen — they declare which post
 * type, ID, and title they render under, emit their own input markup in
 * {@see self::render()}, and persist the submitted values in
 * {@see self::save()}.
 *
 * The save path runs on every `save_post`, so subclasses MUST gate writes
 * behind {@see self::verified()} (autosave / nonce / capability check).
 *
 * Not declared `final` — the {@see \VL\LMS\Admin\AdminProvider} test suite
 * mocks concrete subclasses with Mockery.
 *
 * @author Tymofii Synianskyi
 */
abstract class AbstractMetaBox {

	public function __construct( protected readonly string $post_type ) {
	}

	final public function post_type(): string {
		return $this->post_type;
	}

	abstract public function id(): string;

	abstract public function title(): string;

	/**
	 * Meta-box context — `normal`, `side`, or `advanced`. Defaults to
	 * `normal` for the main column.
	 */
	public function context(): string {
		return 'normal';
	}

	/**
	 * Meta-box priority — `high`, `default`, or `low`. Defaults to
	 * `high` so our typed boxes appear above the WordPress meta-boxes
	 * that follow the editor.
	 */
	public function priority(): string {
		return 'high';
	}

	public function register(): void {
		add_meta_box(
			$this->id(),
			$this->title(),
			[ $this, 'render' ],
			$this->post_type,
			$this->context(),
			$this->priority()
		);
	}

	abstract public function render( WP_Post $post ): void;

	abstract public function save( int $post_id, WP_Post $post ): void;

	/**
	 * Pre-flight gate run from every {@see self::save()} implementation.
	 *
	 * Returns `true` only when:
	 *  - we are not inside an autosave,
	 *  - the post type matches this meta-box,
	 *  - a valid nonce is present in `$_POST`,
	 *  - the current user can edit `$post_id`.
	 */
	protected function verified( int $post_id ): bool {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return false;
		}

		if ( get_post_type( $post_id ) !== $this->post_type ) {
			return false;
		}

		$nonce_field = $this->nonce_field_name();
		if ( ! isset( $_POST[ $nonce_field ] ) ) {
			return false;
		}

		$nonce = sanitize_text_field( wp_unslash( (string) $_POST[ $nonce_field ] ) );
		if ( ! wp_verify_nonce( $nonce, $this->nonce_action() ) ) {
			return false;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return false;
		}

		return true;
	}

	protected function nonce_action(): string {
		return 'vl_lms_' . $this->id() . '_save';
	}

	protected function nonce_field_name(): string {
		return 'vl_lms_' . $this->id() . '_nonce';
	}

	protected function render_nonce(): void {
		wp_nonce_field( $this->nonce_action(), $this->nonce_field_name() );
	}

	/**
	 * Returns a sanitized, unslashed string from `$_POST` or null when
	 * the key is absent.
	 */
	protected function post_string( string $key ): ?string {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in self::verified() before any save handler reads $_POST.
		if ( ! isset( $_POST[ $key ] ) ) {
			return null;
		}
		return sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Returns a raw, unslashed string from `$_POST` for fields that need
	 * type-specific sanitization later (URLs, JSON, HTML).
	 */
	protected function post_raw( string $key ): ?string {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in self::verified() before any save handler reads $_POST.
		if ( ! isset( $_POST[ $key ] ) ) {
			return null;
		}
		return wp_unslash( (string) $_POST[ $key ] );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	protected function post_checkbox( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in self::verified() before any save handler reads $_POST.
		return isset( $_POST[ $key ] ) ? '1' : '';
	}

	/**
	 * @param list<string> $allowed
	 */
	protected function post_enum( string $key, array $allowed ): ?string {
		$value = $this->post_string( $key );
		if ( null === $value ) {
			return null;
		}
		return in_array( $value, $allowed, true ) ? $value : null;
	}

	protected function post_int( string $key, int $min = PHP_INT_MIN, int $max = PHP_INT_MAX ): ?int {
		$value = $this->post_string( $key );
		if ( null === $value || '' === $value ) {
			return null;
		}
		$int = (int) $value;
		if ( $int < $min ) {
			$int = $min;
		}
		if ( $int > $max ) {
			$int = $max;
		}
		return $int;
	}

	protected function post_float( string $key, float $min = -INF, float $max = INF ): ?float {
		$value = $this->post_string( $key );
		if ( null === $value || '' === $value ) {
			return null;
		}
		$flt = (float) $value;
		if ( $flt < $min ) {
			$flt = $min;
		}
		if ( $flt > $max ) {
			$flt = $max;
		}
		return $flt;
	}

	/**
	 * Renders a labelled `<input>` row.
	 */
	protected function render_text_row(
		string $name,
		string $label,
		string $value,
		string $type = 'text',
		string $extra_attrs = ''
	): void {
		printf(
			'<div class="vl-lms-row"><label for="%1$s">%2$s</label>'
			. '<input type="%3$s" id="%1$s" name="%1$s" value="%4$s" %5$s /></div>',
			esc_attr( $name ),
			esc_html( $label ),
			esc_attr( $type ),
			esc_attr( $value ),
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $extra_attrs is built from caller-controlled, hardcoded strings.
			$extra_attrs
		);
	}

	/**
	 * Renders a labelled `<select>` row.
	 *
	 * @param array<string, string> $options Value → label map.
	 */
	protected function render_select_row(
		string $name,
		string $label,
		string $current,
		array $options
	): void {
		printf(
			'<div class="vl-lms-row"><label for="%1$s">%2$s</label><select id="%1$s" name="%1$s">',
			esc_attr( $name ),
			esc_html( $label )
		);
		foreach ( $options as $value => $option_label ) {
			printf(
				'<option value="%1$s"%3$s>%2$s</option>',
				esc_attr( (string) $value ),
				esc_html( $option_label ),
				selected( $current, (string) $value, false )
			);
		}
		echo '</select></div>';
	}

	/**
	 * Renders a labelled `<input type="checkbox">` row.
	 */
	protected function render_checkbox_row( string $name, string $label, bool $checked ): void {
		printf(
			'<div class="vl-lms-row vl-lms-row--checkbox"><label for="%1$s">'
			. '<input type="checkbox" id="%1$s" name="%1$s" value="1" %2$s /> %3$s</label></div>',
			esc_attr( $name ),
			$checked ? 'checked' : '',
			esc_html( $label )
		);
	}

	protected function render_textarea_row(
		string $name,
		string $label,
		string $value,
		int $rows = 4
	): void {
		printf(
			'<div class="vl-lms-row vl-lms-row--full"><label for="%1$s">%2$s</label>'
			. '<textarea id="%1$s" name="%1$s" rows="%3$d" class="large-text">%4$s</textarea></div>',
			esc_attr( $name ),
			esc_html( $label ),
			(int) $rows,
			esc_textarea( $value )
		);
	}

	protected function render_section_heading( string $text ): void {
		printf( '<h3 class="vl-lms-section-heading">%s</h3>', esc_html( $text ) );
	}

	/**
	 * Renders an inline advisory notice inside the box.
	 *
	 * Unlike {@see self::render_readonly_row()} this carries no meta value —
	 * it exists to warn an editor about a *combination* of settings that is
	 * individually valid but jointly harmful (the caller decides when to
	 * emit it). Reuses core's `notice` classes so it inherits wp-admin's
	 * colour vocabulary rather than inventing one.
	 *
	 * `$level` is one of core's notice modifiers — `warning`, `error`,
	 * `info`, `success`.
	 */
	protected function render_inline_notice( string $text, string $level = 'warning' ): void {
		printf(
			'<div class="notice notice-%1$s inline vl-lms-inline-notice"><p>%2$s</p></div>',
			esc_attr( $level ),
			esc_html( $text )
		);
	}

	/**
	 * Renders a read-only display row for a meta value managed elsewhere
	 * (typically the Zoom synchronizer / webhook handlers).
	 */
	protected function render_readonly_row( string $label, string $value, ?string $hint = 'Заповнюється автоматично' ): void {
		echo '<div class="vl-lms-row vl-lms-row--readonly">';
		printf( '<label>%s</label>', esc_html( $label ) );
		echo '<div class="vl-lms-readonly-value">';
		if ( '' === $value ) {
			echo '<span class="vl-lms-readonly-empty">—</span>';
		} else {
			printf( '<code>%s</code>', esc_html( $value ) );
		}
		if ( null !== $hint && '' !== $hint ) {
			printf( '<span class="vl-lms-readonly-hint">%s</span>', esc_html( $hint ) );
		}
		echo '</div></div>';
	}

	protected function meta_string( int $post_id, string $key ): string {
		return (string) get_post_meta( $post_id, $key, true );
	}

	/**
	 * Convert an HTML `datetime-local` input value (no timezone, e.g.
	 * `2026-05-08T14:30`) to the ISO 8601 form the `_vl_*_scheduled_*`
	 * meta sanitizers accept (`Y-m-d\TH:i:sP`, e.g.
	 * `2026-05-08T14:30:00+03:00`). The site timezone (`wp_timezone()`)
	 * is the natural interpretation since the input shows wall-clock
	 * time to the editor. Empty / unparseable input collapses to `''`.
	 */
	protected function datetime_local_to_iso8601( string $value ): string {
		if ( '' === $value ) {
			return '';
		}
		try {
			$dt = new \DateTimeImmutable( $value, wp_timezone() );
		} catch ( \Throwable ) {
			return '';
		}
		return $dt->format( 'Y-m-d\\TH:i:sP' );
	}

	/**
	 * Convert a stored ISO 8601 datetime back to the wall-clock format
	 * the HTML `datetime-local` input expects. Empty or unparseable
	 * input collapses to `''`.
	 */
	protected function iso8601_to_datetime_local( string $value ): string {
		if ( '' === $value ) {
			return '';
		}
		try {
			$dt = new \DateTimeImmutable( $value );
		} catch ( \Throwable ) {
			return '';
		}
		return $dt->setTimezone( wp_timezone() )->format( 'Y-m-d\\TH:i:s' );
	}

	protected function meta_int( int $post_id, string $key ): int {
		return (int) get_post_meta( $post_id, $key, true );
	}

	protected function meta_float( int $post_id, string $key ): float {
		return (float) get_post_meta( $post_id, $key, true );
	}

	protected function meta_bool( int $post_id, string $key ): bool {
		return (bool) get_post_meta( $post_id, $key, true );
	}

	/**
	 * Renders the attachment-list widget shared by lessons, sessions, and webinars.
	 *
	 * The shape of the JSON value held in `{$meta_key}` is `[{url, name, size}]`.
	 * The widget is fully driven by `admin-meta-boxes.js` — this method only
	 * emits the initial markup and a hidden `_json` field that JS keeps in sync.
	 */
	protected function render_attachment_list_widget( int $post_id, string $meta_key, string $label ): void {
		$existing = get_post_meta( $post_id, $meta_key, true );
		$items    = [];
		if ( is_string( $existing ) && '' !== $existing ) {
			$decoded = json_decode( $existing, true );
			if ( is_array( $decoded ) ) {
				foreach ( $decoded as $item ) {
					if ( ! is_array( $item ) ) {
						continue;
					}
					$items[] = [
						'url'  => isset( $item['url'] ) ? (string) $item['url'] : '',
						'name' => isset( $item['name'] ) ? (string) $item['name'] : '',
						'size' => isset( $item['size'] ) ? (int) $item['size'] : 0,
					];
				}
			}
		}

		$json_field = $meta_key . '_json';

		printf(
			'<div class="vl-lms-row vl-lms-row--full vl-lms-attachments" data-meta-key="%1$s">'
			. '<label>%2$s</label>'
			. '<ul class="vl-lms-attachment-list">',
			esc_attr( $meta_key ),
			esc_html( $label )
		);

		foreach ( $items as $item ) {
			printf(
				'<li class="vl-lms-attachment-item" data-url="%1$s" data-name="%2$s" data-size="%3$d">'
				. '<span class="vl-lms-attachment-name">%2$s</span>'
				. '<span class="vl-lms-attachment-size">%4$s</span>'
				. '<button type="button" class="button vl-lms-attachment-pick">Обрати файл</button>'
				. '<button type="button" class="button-link vl-lms-attachment-remove">Видалити</button>'
				. '</li>',
				esc_attr( $item['url'] ),
				esc_html( $item['name'] ),
				(int) $item['size'],
				esc_html( size_format( (int) $item['size'] ) )
			);
		}

		printf(
			'</ul>'
			. '<button type="button" class="button vl-lms-attachment-add">Додати вкладення</button>'
			. '<input type="hidden" name="%1$s" value=\'%2$s\' />'
			. '</div>',
			esc_attr( $json_field ),
			esc_attr( (string) wp_json_encode( $items ) )
		);
	}

	/**
	 * Decodes and validates an attachment-list payload posted via the
	 * `{$meta_key}_json` hidden field. Returns a JSON-encoded canonical
	 * form ready for `update_post_meta`, or `null` when the payload is
	 * absent / malformed (caller decides whether to clear or skip).
	 */
	protected function sanitize_attachment_list_post( string $meta_key ): ?string {
		$raw = $this->post_raw( $meta_key . '_json' );
		if ( null === $raw ) {
			return null;
		}
		if ( '' === trim( $raw ) ) {
			return wp_json_encode( [] );
		}
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return wp_json_encode( [] );
		}
		$items = [];
		foreach ( $decoded as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$url = isset( $item['url'] ) ? esc_url_raw( (string) $item['url'] ) : '';
			if ( '' === $url ) {
				continue;
			}
			$name = isset( $item['name'] ) ? sanitize_text_field( (string) $item['name'] ) : '';
			$size = isset( $item['size'] ) ? (int) $item['size'] : 0;
			if ( $size < 0 ) {
				$size = 0;
			}
			$items[] = [
				'url'  => $url,
				'name' => $name,
				'size' => $size,
			];
		}
		return wp_json_encode( $items );
	}
}
