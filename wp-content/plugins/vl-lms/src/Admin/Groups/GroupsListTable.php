<?php

declare(strict_types=1);

namespace VL\LMS\Admin\Groups;

use VL\LMS\Domain\Group\Group;
use VL\LMS\Domain\Group\GroupStatus;
use VL\LMS\Repositories\GroupAccessRepository;
use VL\LMS\Repositories\GroupMemberRepository;
use VL\LMS\Repositories\GroupRepository;

/**
 * Admin "Групи" list screen.
 *
 * Mirrors {@see \VL\LMS\Admin\Orders\OrdersListTable} — `\WP_List_Table`
 * subclass with status filter + search + pagination round-tripped via
 * the URL. Page-controller in {@see GroupsListPage} wraps this in the
 * `<form method="get">` and search box.
 *
 * @author Tymofii Synianskyi
 */
class GroupsListTable extends \WP_List_Table {

	public const string PAGE_SLUG = 'vl-lms-groups';

	private const int PER_PAGE = 20;

	public function __construct(
		private readonly GroupRepository $groups,
		private readonly GroupMemberRepository $members,
		private readonly GroupAccessRepository $access
	) {
		parent::__construct(
			[
				'singular' => 'group',
				'plural'   => 'groups',
				'ajax'     => false,
			]
		);
	}

	/**
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return [
			'name'         => 'Назва',
			'slug'         => 'Slug',
			'status'       => 'Статус',
			'member_count' => 'Учасники',
			'access_count' => 'Курси',
			'created_at'   => 'Створено',
		];
	}

	/**
	 * @return array<string, string>
	 */
	public function get_bulk_actions(): array {
		return [];
	}

	public function prepare_items(): void {
		$this->_column_headers = [ $this->get_columns(), [], [] ];

		$status = $this->normalize_status( $this->request_string( 'status' ) );
		$search = $this->request_string( 's' );

		$page = (int) ( $this->request_string( 'paged' ) ?? '1' );
		$page = max( 1, $page );

		$offset = ( $page - 1 ) * self::PER_PAGE;
		$total  = $this->groups->count( $status, $search );

		$this->items = $this->groups->paginate( $status, $search, self::PER_PAGE, $offset );

		$this->set_pagination_args(
			[
				'total_items' => $total,
				'per_page'    => self::PER_PAGE,
				'total_pages' => max( 1, (int) ceil( $total / self::PER_PAGE ) ),
			]
		);
	}

	/**
	 * @param Group  $item
	 * @param string $column_name
	 */
	public function column_default( $item, $column_name ): string {
		return match ( $column_name ) {
			'slug'       => '<code>' . esc_html( $item->slug ) . '</code>',
			default      => '',
		};
	}

	public function column_name( Group $item ): string {
		$href = add_query_arg(
			[
				'page'   => self::PAGE_SLUG,
				'action' => 'edit',
				'id'     => $item->id,
			],
			admin_url( 'admin.php' )
		);
		return sprintf(
			'<strong><a href="%s">%s</a></strong>',
			esc_url( $href ),
			esc_html( $item->name )
		);
	}

	public function column_status( Group $item ): string {
		$label = GroupStatus::ACTIVE === $item->status ? 'Активна' : 'Архів';
		return sprintf(
			'<span class="vl-status-badge vl-status-%s">%s</span>',
			esc_attr( $item->status->value ),
			esc_html( $label )
		);
	}

	public function column_member_count( Group $item ): string {
		return (string) $this->members->count_active_members( $item->id );
	}

	public function column_access_count( Group $item ): string {
		return (string) count( $this->access->list_active_for_group( $item->id ) );
	}

	public function column_created_at( Group $item ): string {
		$ts = strtotime( $item->created_at . ' UTC' );
		if ( false === $ts ) {
			return esc_html( $item->created_at );
		}
		return esc_html( wp_date( 'd.m.Y H:i', $ts ) );
	}

	/**
	 * @param string $which
	 */
	public function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}

		$current = $this->request_string( 'status' ) ?? '';
		$options = [
			''         => 'Усі статуси',
			'active'   => 'Активні',
			'archived' => 'Архівні',
		];

		echo '<div class="alignleft actions">';
		echo '<select name="status">';
		foreach ( $options as $value => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( (string) $value ),
				selected( $current, $value, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
		submit_button( 'Фільтрувати', '', 'filter_action', false );
		echo '</div>';
	}

	public function no_items(): void {
		esc_html_e( 'Груп не знайдено. Створіть першу нижче ↓', 'vl-lms' );
	}

	private function normalize_status( ?string $value ): ?GroupStatus {
		if ( null === $value || '' === $value ) {
			return null;
		}
		foreach ( GroupStatus::cases() as $case ) {
			if ( $case->value === $value ) {
				return $case;
			}
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
}
